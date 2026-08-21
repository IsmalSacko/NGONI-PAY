<?php

namespace App\Http\Resources;

use App\Enums\BusinessType;
use App\Enums\SubscriptionRequestStatus;
use App\Services\SubscriptionRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande d'abonnement, telle que l'application et la console la lisent.
 */
class SubscriptionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan,
            'method' => $this->method,
            'amount_due' => (float) $this->amount_due,
            'currency' => $this->currency,
            'months' => $this->months,
            'cycle' => $this->cycle?->value,
            'cycle_label' => $this->cycle?->label(),
            'note' => $this->note,
            'contact_phone' => $this->contact_phone,
            // Chemin brut inutile côté client : seule l'URL sert à l'afficher.
            'proof_url' => $this->proofUrl(),
            'proof_note' => $this->proof_note,
            // Ce qu'accorderait l'approbation, calculé à l'instant : le
            // commerçant comme l'exploitant doivent voir la date avant de
            // décider, pas la découvrir après. `null` = sans échéance.
            'projected_ends_at' => $this->status === SubscriptionRequestStatus::Pending
                ? app(SubscriptionRequestService::class)
                    ->projectedEndDate($this->resource)
                    ?->toIso8601String()
                : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at,

            'business' => $this->whenLoaded('business', fn () => [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'type_label' => BusinessType::labelFor($this->business->type),
                'phone' => $this->business->phone,
                'currency' => $this->business->currency ?: 'XOF',
            ]),

            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
                'phone' => $this->requestedBy->phone,
            ] : null),
        ];
    }
}
