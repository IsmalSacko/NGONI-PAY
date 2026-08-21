<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarif d'un plan pour une durée donnée.
 *
 * Rien n'impose qu'un trimestre vaille trois fois le mois : l'exploitant fixe
 * chaque montant, et peut donc accorder une remise sur les durées longues — ou
 * fermer une durée en la désactivant.
 */
class SubscriptionPlanPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_plan_id',
        'cycle',
        'amount',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => BillingCycle::class,
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Nombre de mois que ce tarif couvre.
     */
    public function months(): int
    {
        return $this->cycle->months();
    }
}
