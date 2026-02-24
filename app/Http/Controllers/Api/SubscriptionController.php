<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Payments\PayDunyaClient;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionRequest;

class SubscriptionController extends Controller
{
    public function show(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        $subscription = $business->subscription;
        $now = Carbon::now();

        if (! $subscription) {
            $subscription = Subscription::create([
                'business_id' => $business->id,
                'plan' => 'free',
                'is_active' => true,
                'starts_at' => $now,
                'ends_at' => $now->copy()->addDays(7),
            ]);
            return new SubscriptionResource($subscription);
        }

        if ($subscription->ends_at && Carbon::parse($subscription->ends_at)->lt($now)) {
            if ($subscription->plan !== 'free') {
                $subscription->update([
                    'plan' => 'free',
                    'is_active' => true,
                    'starts_at' => $now,
                    // Downgrade vers Free sans relancer une nouvelle période d'essai.
                    'ends_at' => $now,
                ]);
            }
        }

        if ($subscription->plan === 'free' && $subscription->ends_at) {
            // L'essai Free ne doit jamais dépasser 7 jours à partir de starts_at.
            $trialMax = Carbon::parse($subscription->starts_at)->copy()->addDays(7);
            if (Carbon::parse($subscription->ends_at)->gt($trialMax)) {
                $subscription->update([
                    'ends_at' => $trialMax,
                ]);
            }
        }

        return new SubscriptionResource($subscription->fresh());
    }

    public function store(StoreSubscriptionRequest $request, Business $business, PayDunyaClient $payDunyaCli)
    {
        $this->authorizeManager($business, $request);
        // Pour le cas d'un abonnement gratuit: essai unique, non renouvelable.
        if ($request->plan === 'free') {
            $existing = Subscription::where('business_id', $business->id)->first();

            if (! $existing) {
                $subscription = Subscription::create([
                    'business_id' => $business->id,
                    'plan' => 'free',
                    'is_active' => true,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(7),
                ]);

                return new SubscriptionResource($subscription);
            }

            // Si l'essai est déjà passé, on bascule/maintient en Free expiré (sans prolongation).
            $now = now();
            $currentEnd = $existing->ends_at ? Carbon::parse($existing->ends_at) : null;
            $isFreeTrialStillRunning = $existing->plan === 'free' && $currentEnd && $currentEnd->isAfter($now);

            if ($isFreeTrialStillRunning) {
                return new SubscriptionResource($existing);
            }

            $existing->update([
                'plan' => 'free',
                'is_active' => true,
                'starts_at' => $now,
                'ends_at' => $now, // aucune nouvelle semaine d'essai
            ]);

            return new SubscriptionResource($existing->fresh());
        }
        // Pour le basic/ pro
        $mount = $this->planPrice($request->plan);
        $method = $request->input('method');

        if(! $method){
            throw ValidationException::withMessages(
                ['method' => 'Le mode de paiement est requis.']
            );
        }
        $owner = $business->owner;
        $clientName = $business->name ?: ($owner?->name ?? 'Abonnement');
        $clientPhone = $business->phone ?: ($owner?->phone ?? 'SUBSCRIPTION-' . $business->id);

        $client = Client::firstOrCreate(
            [
                'business_id' => $business->id,
                'phone' => $clientPhone,
            ],
            [
                'name' => $clientName,
                // Email unique globalement : éviter d'écraser si déjà utilisé ailleurs
                'email' => null,
            ]
        );

        $payment = Payment::create([
                'business_id' => $business->id,
                'client_id' => $client->id,
                'user_id' => $request->user()->id,
                'amount' => $mount,
                'currency' => 'XOF',
                'method' => $method,
                'provider' => $method === 'cash' ? null : 'paydunya',
                'purpose' => 'subscription',
                'status' => $method === 'cash' ? 'success' : 'pending',
                'transaction_ref' => (string) Str::uuid(), // Générer une référence unique
            ]);
        // 🔹 CASH → activation immédiate
        if ($method === 'cash') {
            $this->activateSubscription($business, $request->plan);

            return response()->json([
                'message' => 'Abonnement activé (paiement cash)',
                'subscription' => $business->fresh()->subscription,
            ]);
        }
        // 🔹 MOBILE → PayDunya
        $response = $payDunyaCli->createInvoiceForSubscription($payment, $business);

        if (! $response['successful']) {
            throw ValidationException::withMessages([
                'payment' => ['Impossible de lancer le paiement abonnement'],
            ]);
        }

        $payment->update([
            'provider_reference' => data_get($response['data'], 'token')
                ?? data_get($response['data'], 'response_code'),
            'provider_checkout_url' => data_get($response['data'], 'invoice_url')
                ?? data_get($response['data'], 'redirect_url')
                ?? data_get($response['data'], 'response_text'),
        ]);

        return response()->json([
            'payment_id' => $payment->id,
            'checkout_url' => $payment->provider_checkout_url,
        ]);


    }

    private function activateSubscription(Business $business, string $plan): void
    {
        $endsAt = now()->addMonth();
        if ($plan === 'pro') {
            $endsAt = now()->addMonth();
        } elseif ($plan === 'basic') {
            $endsAt = now()->addMonth();
        } elseif ($plan === 'free') {
            $endsAt = now()->addDays(7);
        }

        Subscription::updateOrCreate(
            ['business_id' => $business->id],
            [
                'plan' => $plan,
                'is_active' => true,
                'starts_at' => now(),
                'ends_at' => $endsAt,
            ]
        );
    }

    // Fonction pour retourner le prix en fonction du plan
    private function planPrice(string $plan): int
    {
        return match ($plan) {
            'basic' => 5000,
            'pro'   => 15000,
            default => 0,
        };
    }

    public function update(UpdateSubscriptionRequest $request, Business $business, Subscription $subscription)
    {
        $this->authorizeManager($business, $request);

        if ($subscription->business_id !== $business->id) {
            abort(404);
        }

        $subscription->update($request->validated());

        return new SubscriptionResource($subscription);
    }

    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($business->owner_id === $user->id) return;

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) abort(403, "Accès interdit: cette entreprise ne vous appartient pas.");
    }
}
