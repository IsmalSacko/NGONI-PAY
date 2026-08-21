<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\SubscriptionRequestResource;
use App\Models\Business;
use App\Models\Subscription;
use App\Services\SubscriptionRequestService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionRequest;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionRequestService $requests) {}

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

    public function store(StoreSubscriptionRequest $request, Business $business)
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
        // 🔹 PLANS PAYANTS → demande à instruire, quel que soit le moyen
        //
        // Le règlement se fait hors application, et aucun canal ne prouve à lui
        // seul que l'argent est arrivé : « espèces » activait le plan sur simple
        // déclaration. Toute souscription payante devient donc une demande, que
        // l'exploitant approuve quand il a constaté le paiement.
        //
        // Le paiement en ligne reste possible, mais il est en pause faute de clés
        // de production : voir `services.paydunya.subscriptions_enabled`. Quand il
        // sera rallumé, le mobile money repassera par le fournisseur, dont le
        // rappel active le plan sans intervention ({@see PaymentCallbackController})
        // — les espèces continueront de passer par une demande.
        $demande = $this->requests->submit(
            business: $business,
            plan: (string) $request->plan,
            requestedBy: $request->user(),
            method: $request->input('method'),
        );

        return response()->json([
            'message' => 'Demande enregistrée. Votre abonnement sera activé par '
                . 'NGONI PAY dès validation du paiement.',
            'code' => 'SUBSCRIPTION_PENDING_VALIDATION',
            'request' => new SubscriptionRequestResource($demande),
            // Le plan courant, inchangé : l'application ne doit pas annoncer un
            // abonnement actif.
            'subscription' => $business->fresh()->subscription,
        ], 202);
    }

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
