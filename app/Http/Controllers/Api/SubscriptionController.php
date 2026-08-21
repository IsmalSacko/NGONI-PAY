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

        // Abonnement accordé manuellement par un admin : ne jamais le rétrograder
        // ni recadrer automatiquement (ends_at NULL = à vie).
        if ($subscription->is_manual) {
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
                // L'abonnement est facturé par l'éditeur, en francs CFA, quelle
                // que soit la devise dans laquelle le business tient ses
                // comptes : c'est un prix catalogue, pas un encaissement.
                'currency' => 'XOF',
                'method' => $method,
                'provider' => $method === 'cash' ? null : 'paydunya',
                'purpose' => Payment::PURPOSE_SUBSCRIPTION,
                // Un abonnement n'est encaissé que lorsque l'argent est arrivé :
                // le fournisseur le confirme par rappel, ou un administrateur le
                // constate. Jamais sur la seule déclaration du demandeur.
                'status' => 'pending',
                'transaction_ref' => (string) Str::uuid(), // Générer une référence unique
            ]);

        // 🔹 ESPÈCES → demande à valider, pas activation
        //
        // Le plan était activé sur-le-champ dès que « cash » était choisi :
        // n'importe quel utilisateur s'accordait un mois de Pro en tapant deux
        // fois sur son téléphone, sans qu'un franc ne change de main. La demande
        // est désormais enregistrée en attente, et c'est un administrateur qui
        // l'honore une fois l'argent reçu ({@see AdminSubscriptionController}).
        if ($method === 'cash') {
            return response()->json([
                'message' => 'Demande enregistrée. Votre abonnement sera activé '
                    . 'par NGONI PAY dès réception du paiement en espèces.',
                'code' => 'SUBSCRIPTION_PENDING_VALIDATION',
                'payment_id' => $payment->id,
                'subscription' => $business->fresh()->subscription,
            ], 202);
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

    // Fonction pour retourner le prix en fonction du plan
    private function planPrice(string $plan): int
    {
        return match ($plan) {
            'basic' => 5000,
            'pro'   => 15000,
            default => 0,
        };
    }

    /**
     * Modification directe d'un abonnement : réservée aux administrateurs.
     *
     * Le propriétaire du business y avait accès, et la requête accepte `plan`,
     * `ends_at` et `is_active` : un `PUT` suffisait à s'accorder un plan Pro à
     * vie, sans payer et sans passer par la moindre vérification. Aucun écran de
     * l'application n'appelle cette route — seuls les outils d'administration.
     */
    public function update(UpdateSubscriptionRequest $request, Business $business, Subscription $subscription)
    {
        abort_unless(
            $request->user()->isSystemAdmin(),
            403,
            "Seul un administrateur peut modifier un abonnement. Souscrivez depuis l'application.",
        );

        if ($subscription->business_id !== $business->id) {
            abort(404);
        }

        $subscription->update($request->validated());

        return new SubscriptionResource($subscription);
    }

    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) return;

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) abort(403, "Accès interdit: cette entreprise ne vous appartient pas.");
    }
}
