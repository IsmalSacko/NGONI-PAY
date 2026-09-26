<?php

namespace App\Http\Controllers\Api;

use App\Enums\BillingCycle;
use App\Http\Resources\SubscriptionRequestResource;
use App\Models\Business;
use App\Models\Subscription;
use App\Services\SubscriptionRequestService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionRequest;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionRequestService $requests) {}

    /**
     * Abonnement de l'entreprise. Le tout premier appel démarre l'essai ; ensuite
     * il n'est jamais prolongé ni recréé. Un essai ou un plan expiré est renvoyé
     * tel quel (`is_currently_active` à false) : l'application bloque l'encaissement.
     */
    public function show(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        $subscription = $business->subscription ?? Subscription::startTrial($business);

        return new SubscriptionResource($subscription);
    }

    /**
     * Souscription à un plan payant : toujours une demande à instruire.
     *
     * Le règlement se fait hors application (espèces, mobile money, virement) et
     * aucun canal ne prouve à lui seul que l'argent est arrivé. L'exploitant est
     * prévenu, constate le paiement, puis approuve depuis la console.
     */
    public function store(StoreSubscriptionRequest $request, Business $business)
    {
        $this->authorizeManager($business, $request);

        $demande = $this->requests->submit(
            business: $business,
            plan: (string) $request->plan,
            requestedBy: $request->user(),
            method: $request->input('method'),
            cycle: BillingCycle::tryFrom((string) $request->input('cycle'))
                ?? BillingCycle::Monthly,
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
