<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Business;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Services\Payments\PayDunyaClient;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Business $business,PayDunyaClient $payDunyaCli)
    {
        // Client existant
        if ($request->filled('client_id')) {
            $client = Client::where('business_id', $business->id)
                ->findOrFail($request->client_id);
        }
        // Client spontané
        else {
            $client = Client::firstOrCreate(
                ['phone' => $this->normalizePhone($request->phone), 'business_id' => $business->id,],
                ['name' => $request->name ?? 'Client spontané',]
            );
        }

        // Met à jour le nom du client si fourni et différent
        if ($request->filled('name') && $client->name !== $request->name) {
            $client->update(['name' => $request->name]);
        }

        // Defensive check: ensure 'method' exists in input. If missing, return a validation error
        $method = $request->input('method');
        if (empty($method)) {
            throw ValidationException::withMessages([
                'method' => ["Le champ 'method' est requis."],
            ]);
        }
        $subscription = $business->subscription;

        // Aucun abonnement actif
        if (! $subscription || ! $subscription->is_active) {
            abort(403, 'Aucun abonnement actif');
        }

        // Si abonnement expiré, rétrograder immédiatement en Free sans relancer d'essai.
        if ($subscription->ends_at && Carbon::parse($subscription->ends_at)->endOfDay()->lt(Carbon::now())) {
            if ($subscription->plan !== 'free') {
                $subscription->update([
                    'plan' => 'free',
                    'is_active' => true,
                    'starts_at' => now(),
                    'ends_at' => now(),
                ]);
                $subscription->refresh();
            }
        }

        $isFreePlan = $subscription->plan === 'free';
        $isFreeTrialExpired = $isFreePlan
            && $subscription->ends_at
            && Carbon::parse($subscription->ends_at)->endOfDay()->lt(Carbon::now());

        if ($isFreeTrialExpired && $method !== 'cash') {
            return response()->json([
                'message' => 'Période d’essai Free expirée. Passez au plan Basic ou Pro pour les paiements en ligne.',
                'code' => 'FREE_TRIAL_EXPIRED',
            ], 403);
        }

        $provider = ($method === 'cash' || $isFreePlan) ? null : 'paydunya';

        // FREE → pas de PayDunya (paiement enregistré comme offline)

        // BASIC → max 5 paiements PayDunya / mois
        if ($subscription->plan === 'basic' && $method !== 'cash') {

            $usedThisMonth = Payment::where('business_id', $business->id)
                ->where('purpose', 'sale')
                ->where('provider', 'paydunya')
                ->where('status', 'success')
                ->whereBetween('paid_at', [
                    Carbon::now()->startOfMonth(),
                    Carbon::now()->endOfMonth(),
                ])
                ->count();

            if ($usedThisMonth >= 5) {
                return response()->json([
                    'message' => 'Limite mensuelle atteinte (5 paiements PayDunya)',
                    'code' => 'PAYMENT_LIMIT_REACHED',
                ], 403);
            }
        }

        $payment = Payment::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'XOF',
            'method' => $method,
            'provider' => $provider,
            'transaction_ref' => (string) Str::uuid(),
            'status' => $provider === null ? 'success' : 'pending',
            'paid_at' => $provider === null ? now() : null,
        ]);

               if ($provider === 'paydunya') {
            $response = $payDunyaCli->createInvoice($payment, $client);

            if (! $response['successful']) {
                Log::warning('PayDunya invoice creation failed', [
                    'payment_id' => $payment->id,
                    'status' => $response['status'],
                    'response' => $response['data'],
                ]);

                throw ValidationException::withMessages([
                    'payment' => [
                        data_get($response, 'data.message')
                        ?? data_get($response, 'data.response_text')
                            ?? data_get($response, 'data.response_code')
                            ?? 'Impossible de créer la facture PayDunya pour le moment.',
                    ],
                ]);

            }

            $payment->update([
                'provider_reference' => data_get($response['data'], 'token')
                    ?? data_get($response['data'], 'response_code'),
                'provider_checkout_url' => data_get($response['data'], 'invoice_url')
                    ?? data_get($response['data'], 'redirect_url')
                    ?? data_get($response['data'], 'response_text'),
            ]);
        } else {
            $payment->invoice()->firstOrCreate(
                ['payment_id' => $payment->id],
                [
                    'invoice_number' => 'NGONI-FACTURE-' . now()->format('Ymd') . '-' . $payment->id,
                    'total_amount' => $payment->amount,
                    'sent_via' => 'none',
                ]
            );
        }

        return new PaymentResource($payment->load('client'));
    }

    public function index(Request $request, Business $business)
    {
        // 🔐 Vérifie que l'utilisateur est propriétaire / autorisé
        $this->authorizeOwner($business, $request);

        $payments = Payment::with('client')
            ->where('business_id', $business->id)
            ->latest()
            ->get();

        return PaymentResource::collection($payments);
    }


    public function show(Request $request, Business $business, Payment $payment)
    {
        // Ensure the payment belongs to the requested business
        if ($payment->business_id !== $business->id) {
            abort(404);
        }

        $payment->load(['client:id,name,phone']);

        return new PaymentResource($payment);
    }

    /**
     * Annule un paiement enregistré par erreur (soft cancel).
     * Réservé au propriétaire ou à un manager. Le paiement n'est pas supprimé :
     * il passe au statut 'cancelled' (exclu des totaux, mais tracé).
     */
    public function cancel(Request $request, Business $business, Payment $payment)
    {
        $this->authorizeManager($business, $request);

        if ($payment->business_id !== $business->id) {
            abort(404);
        }

        if ($payment->status === 'cancelled') {
            return response()->json(['message' => 'Ce paiement est déjà annulé.'], 422);
        }

        // Ne pas annuler un paiement d'abonnement (gestion séparée).
        if ($payment->purpose === 'subscription') {
            return response()->json(
                ['message' => "Un paiement d'abonnement ne peut pas être annulé ici."],
                422
            );
        }

        $payment->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancel_reason' => $request->input('reason'),
        ]);

        $payment->load(['client:id,name,phone']);

        return new PaymentResource($payment);
    }

    private function authorizeOwner(Business $business, Request $request): void
    {
        if ($business->owner_id !== $request->user()->id) {
            abort(403, 'Accès interdit');
        }
    }

    /**
     * Autorise le propriétaire OU un manager du business.
     */
    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($business->owner_id === $user->id) {
            return;
        }

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) {
            abort(403, 'Accès interdit');
        }
    }


    private function normalizePhone(string $phone): string
    {
        // Supprimer espaces
        $phone = str_replace(' ', '', $phone);

        // Si déjà au format +223XXXXXXXX
        if (str_starts_with($phone, '+223')) {
            return $phone;
        }

        // Si envoyé sans indicatif (8 chiffres)
        if (preg_match('/^\d{8}$/', $phone)) {
            return '+223' . $phone;
        }

        // Si envoyé comme 223XXXXXXXX
        if (preg_match('/^223\d{8}$/', $phone)) {
            return '+' . $phone;
        }

        // Sinon, on retourne tel quel (ou on peut lever une erreur)
        return $phone;
    }
}
