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

    /**
     * Durée de l'essai, en jours.
     *
     * Sept par défaut : c'est la valeur qui était écrite dans le contrôleur, et
     * qu'un plan sans `trial_days` conserve.
     */
    public function trialDays(): int
    {
        return $this->trial_days ?? 7;
    }

    /**
     * Plan de ce code, ou `null`. Les tarifs suivent.
     */
    public static function byCode(?string $code): ?self
    {
        $normalise = strtolower(trim((string) $code));
        if ($normalise === '') return null;

        return static::query()->with('prices')->where('code', $normalise)->first();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class);
    }

    /**
     * Le plan est-il l'essai ? Il ne se vend pas : il est offert à la création.
     */
    public function isTrial(): bool
    {
        return $this->code === Subscription::PLAN_TRIAL;
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

    /**
     * Tarif ramené au mois, qui sert d'unité de valeur.
     *
     * C'est la référence qui permet de convertir ce qui reste d'un abonnement en
     * jours d'un autre : sans unité commune, on ne peut que jeter les jours
     * restants ou les reporter tels quels, et les deux sont faux dès que les
     * plans n'ont pas le même prix.
     *
     * Le tarif mensuel s'il est proposé ; sinon le moins cher au mois parmi les
     * durées ouvertes — un plan vendu au trimestre seulement a bien une valeur
     * mensuelle. Zéro pour l'essai : il n'a rien coûté.
     */
    public function monthlyRate(): float
    {
        $mensuel = $this->priceFor(BillingCycle::Monthly);

        if ($mensuel !== null) {
            return (float) $mensuel->amount;
        }

        $taux = $this->prices
            ->where('is_active', true)
            ->map(fn (SubscriptionPlanPrice $price) => (float) $price->amount / max(1, $price->months()))
            ->min();

        return $taux === null ? 0.0 : (float) $taux;
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
