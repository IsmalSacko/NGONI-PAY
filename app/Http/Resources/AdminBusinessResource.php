<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue d'un business pour le tableau de bord d'administration :
 * infos business + propriétaire + abonnement courant.
 */
class AdminBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->subscription;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'phone' => $this->phone,
            'currency' => $this->currency ?: 'XOF',
            'is_active' => (bool) $this->is_active,
            'owner' => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'phone' => $this->owner->phone,
                'country' => $this->owner->country ?: 'ML',
                'email' => $this->owner->email,
            ] : null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'is_active' => (bool) $subscription->is_active,
                'is_manual' => (bool) $subscription->is_manual,
                'is_lifetime' => $subscription->ends_at === null,
                'grants_pro_access' => $subscription->grantsProAccess(),
                'admin_note' => $subscription->admin_note,
            ] : null,
        ];
    }
}
