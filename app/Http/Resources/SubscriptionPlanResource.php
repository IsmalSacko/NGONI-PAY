<?php

namespace App\Http\Resources;

use App\Models\SubscriptionPlanPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan d'abonnement tel que l'application le présente.
 *
 * Les tarifs étaient écrits dans le code mobile, à deux endroits : les ajuster
 * demandait de publier une nouvelle version sur les téléphones. Ils viennent
 * désormais du serveur, où l'exploitant les tient.
 */
class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'trial_days' => $this->trial_days,
            'is_free' => $this->isFree(),
            'prices' => $this->prices
                ->where('is_active', true)
                ->sortBy(fn (SubscriptionPlanPrice $price) => $price->months())
                ->map(fn (SubscriptionPlanPrice $price) => [
                    'cycle' => $price->cycle->value,
                    'cycle_label' => $price->cycle->label(),
                    'unit' => $price->cycle->unit(),
                    'months' => $price->months(),
                    'amount' => (float) $price->amount,
                    'currency' => $price->currency,
                ])
                ->values(),
        ];
    }
}
