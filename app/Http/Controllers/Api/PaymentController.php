<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Business;
use App\Enums\Country;
use App\Support\Money\Currencies;
use App\Support\Phone\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
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
        // Sécurité : seul le propriétaire ou un membre du staff peut encaisser.
        $this->authorizeMember($business, $request);

        // Idempotence : le client mobile rejoue la même requête quand la réponse
        // s'est perdue (réseau instable, file hors ligne). Si ce paiement a déjà
        // été enregistré, on renvoie l'existant au lieu d'en créer un second.
        // Le contrôle passe AVANT les quotas d'abonnement : un rejeu ne doit pas
        // être refusé par une limite que la création initiale a déjà consommée.
        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($business, $idempotencyKey);

            if ($existing) {
                return new PaymentResource($existing->load('client'));
            }
        }

        // Client existant
        if ($request->filled('client_id')) {
            $client = Client::where('business_id', $business->id)
                ->findOrFail($request->client_id);
        }
        // Client spontané
        else {
            $client = Client::firstOrCreate(
                ['phone' => $this->normalizePhone($request->phone, $business), 'business_id' => $business->id,],
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

        try {
            $payment = Payment::create([
                'business_id' => $business->id,
                'client_id' => $client->id,
                'user_id' => $request->user()->id,
                'amount' => $request->amount,
                // La devise vient du business, jamais de la requête : c'est le
                // registre du commerce qui la fixe. Une application restée sur
                // une version antérieure envoie « XOF » en dur, ce qui
                // libellerait en francs CFA les encaissements d'une boutique de
                // Conakry.
                'currency' => Currencies::normalize($business->currency),
                'method' => $method,
                'provider' => $provider,
                'transaction_ref' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'status' => $provider === null ? 'success' : 'pending',
                'paid_at' => $provider === null ? now() : null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Deux requêtes portant la même clé sont arrivées en parallèle : la
            // contrainte unique a tranché. On renvoie le paiement gagnant.
            $existing = $idempotencyKey !== null
                ? $this->findByIdempotencyKey($business, $idempotencyKey)
                : null;

            if (! $existing) {
                throw $e;
            }

            return new PaymentResource($existing->load('client'));
        }

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
        // 🔐 Propriétaire ou manager (cohérent avec l'annulation).
        $this->authorizeManager($business, $request);

        // Les abonnements sont écartés par défaut : ce sont des dépenses payées à
        // l'éditeur, pas des encaissements du commerce. Ils apparaissaient dans
        // la liste des paiements et dans les transactions récentes, où un plan Pro
        // à 15 000 se lisait comme une vente.
        //
        // `?purpose=subscription` les demande explicitement — rien ne devient
        // inatteignable.
        $purpose = $request->query('purpose');

        $payments = Payment::with('client')
            ->where('business_id', $business->id)
            ->when(
                $purpose === Payment::PURPOSE_SUBSCRIPTION,
                fn ($query) => $query->where('purpose', Payment::PURPOSE_SUBSCRIPTION),
                fn ($query) => $query->sales(),
            )
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
        $user = $request->user();
        if ($user->isSystemAdmin()) {
            return;
        }
        if ($business->owner_id !== $user->id) {
            abort(403, 'Accès interdit');
        }
    }

    /**
     * Autorise le propriétaire OU n'importe quel membre du staff (manager/vendeur).
     * Utilisé pour l'encaissement : un vendeur doit pouvoir enregistrer un paiement.
     */
    /**
     * Clé d'idempotence de la requête, ou null si le client n'en fournit pas.
     *
     * On refuse une clé trop longue plutôt que de la tronquer : tronquer
     * pourrait faire collisionner deux paiements distincts, donc en faire
     * disparaître un.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) > 128) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['La clé d’idempotence ne peut pas dépasser 128 caractères.'],
            ]);
        }

        return $key;
    }

    private function findByIdempotencyKey(Business $business, string $key): ?Payment
    {
        return Payment::where('business_id', $business->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function authorizeMember(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) {
            return;
        }

        $isMember = $business->staff()
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'Accès interdit');
        }
    }

    /**
     * Autorise le propriétaire OU un manager du business.
     */
    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) {
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


    /**
     * Numéro du client, sous la forme qui sert de clé dans son business.
     *
     * L'indicatif est celui du pays du propriétaire : c'est là que le commerce
     * encaisse, donc là que ses clients ont leur numéro. Un numéro déjà
     * international est respecté tel quel — un client de passage se paie aussi.
     */
    private function normalizePhone(string $phone, Business $business): string
    {
        return PhoneNumber::normalize(
            $phone,
            $business->owner?->countryEnum() ?? Country::default(),
        );
    }
}
