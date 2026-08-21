<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\StoreSubscriptionRequestRequest;
use App\Http\Resources\SubscriptionRequestResource;
use App\Models\Business;
use App\Models\SubscriptionRequest;
use App\Services\SubscriptionRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demandes d'abonnement, côté commerçant.
 *
 * Il dépose, consulte et retire ses demandes. Il ne les tranche pas : voir
 * {@see Admin\AdminSubscriptionController}.
 */
class SubscriptionRequestController extends Controller
{
    public function __construct(private readonly SubscriptionRequestService $requests) {}

    public function index(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        $demandes = SubscriptionRequest::query()
            ->where('business_id', $business->id)
            ->with(['business', 'requestedBy'])
            ->latest()
            ->get();

        return SubscriptionRequestResource::collection($demandes);
    }

    /**
     * Ce que donnerait l'achat, avant de le demander.
     *
     * L'écran annonce la date de fin et les jours conservés : le commerçant doit
     * voir qu'il ne perd rien **avant** de déposer sa demande. Le calcul reste au
     * serveur — le dupliquer côté mobile le ferait dériver au premier ajustement
     * de tarif.
     */
    public function preview(Request $request, Business $business)
    {
        $this->authorizeManager($business, $request);

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:30'],
            'cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
        ]);

        $cycle = BillingCycle::tryFrom((string) ($data['cycle'] ?? ''))
            ?? BillingCycle::Monthly;

        return response()->json([
            'data' => $this->requests->previewFor(
                $business,
                $data['plan'],
                $cycle->months(),
            ),
        ]);
    }

    public function store(
        StoreSubscriptionRequestRequest $request,
        Business $business,
    ) {
        $this->authorizeManager($business, $request);

        $demande = $this->requests->submit(
            business: $business,
            plan: $request->string('plan')->toString(),
            requestedBy: $request->user(),
            method: $request->input('method'),
            cycle: BillingCycle::tryFrom((string) $request->input('cycle'))
                ?? BillingCycle::Monthly,
            note: $request->input('note'),
            contactPhone: $request->input('contact_phone'),
            proof: $request->file('proof'),
            proofNote: $request->input('proof_note'),
        );

        return (new SubscriptionRequestResource($demande->load(['business', 'requestedBy'])))
            ->response()
            ->setStatusCode(202);
    }

    public function destroy(
        Business $business,
        SubscriptionRequest $subscriptionRequest,
        Request $request,
    ) {
        $this->authorizeManager($business, $request);

        if ($subscriptionRequest->business_id !== $business->id) {
            abort(404);
        }

        return new SubscriptionRequestResource(
            $this->requests->cancel($subscriptionRequest)->load('business'),
        );
    }

    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) return;

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        abort_unless($isManager, 403, "Accès interdit: cette entreprise ne vous appartient pas.");
    }
}
