<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan d'abonnement, tenu par l'exploitant.
 *
 * Les tarifs vivent dans {@see SubscriptionPlanPrice}, une ligne par durée :
 * l'exploitant les ajuste depuis la console, sans déploiement.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'features',
        'trial_days',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class);
    }

    /**
     * Le plan est-il gratuit ?
     *
     * Un plan sans tarif actif ne se paie pas : c'est l'essai.
     */
    public function isFree(): bool
    {
        return $this->code === 'free';
    }

    /**
     * Tarif actif pour cette durée, ou `null` si l'exploitant l'a fermée.
     */
    public function priceFor(BillingCycle $cycle): ?SubscriptionPlanPrice
    {
        return $this->prices
            ->firstWhere(
                fn (SubscriptionPlanPrice $price) => $price->cycle === $cycle
                    && $price->is_active,
            );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
