<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleFacturation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan d'abonnement, tenu par l'exploitant depuis la console : nom, limites,
 * tarifs par durée. `essai` est offert à l'inscription et ne se vend pas.
 */
class Plan extends Model
{
    public const ESSAI = 'essai';

    /** Séances de caisse (fond d'ouverture, clôture, écart) : réservées au Pro. */
    public const SEANCES_CAISSE = 'seances_caisse';

    /**
     * Fonctions qu'un plan inclut ou non, cochées dans la console. Le reste
     * (caisse, catalogue, stocks, clients, tickets…) est dans tous les plans.
     */
    public const FONCTIONNALITES = [
        self::SEANCES_CAISSE => 'Séances de caisse et suivi des écarts',
    ];

    protected $fillable = [
        'code', 'nom', 'description', 'fonctionnalites', 'jours_essai',
        'max_boutiques', 'max_membres', 'est_actif', 'ordre',
    ];

    protected function casts(): array
    {
        return [
            'fonctionnalites' => 'array',
            'jours_essai' => 'integer',
            'max_boutiques' => 'integer',
            'max_membres' => 'integer',
            'est_actif' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlanTarif, $this>
     */
    public function tarifs(): HasMany
    {
        return $this->hasMany(PlanTarif::class);
    }

    public static function parCode(?string $code): ?self
    {
        $code = strtolower(trim((string) $code));

        return $code === '' ? null : static::with('tarifs')->where('code', $code)->first();
    }

    public function estEssai(): bool
    {
        return $this->code === self::ESSAI;
    }

    /** L'essai couvre tout ; un plan payant, ce qui est coché pour lui. */
    public function inclut(string $fonctionnalite): bool
    {
        return $this->estEssai() || in_array($fonctionnalite, $this->fonctionnalites ?? [], true);
    }

    /** @return list<string> */
    public function fonctionnalitesIncluses(): array
    {
        return array_values(array_filter(array_keys(self::FONCTIONNALITES), fn (string $f) => $this->inclut($f)));
    }

    public function joursEssai(): int
    {
        return $this->jours_essai ?? 7;
    }

    public function tarif(CycleFacturation $cycle): ?PlanTarif
    {
        return $this->tarifs->first(fn (PlanTarif $t) => $t->cycle === $cycle && $t->est_actif);
    }

    /**
     * Tarif ramené au mois : l'unité qui permet de convertir les jours restants
     * d'un plan en jours d'un autre. Zéro pour l'essai.
     */
    public function tarifMensuel(): float
    {
        $mensuel = $this->tarif(CycleFacturation::Mensuel);
        if ($mensuel !== null) {
            return (float) $mensuel->montant;
        }

        $taux = $this->tarifs->where('est_actif', true)
            ->map(fn (PlanTarif $t) => $t->montant / max(1, $t->cycle->mois()))
            ->min();

        return $taux === null ? 0.0 : (float) $taux;
    }

    public function scopeActifs($query)
    {
        return $query->where('est_actif', true);
    }

    public function scopeOrdonnes($query)
    {
        return $query->orderBy('ordre')->orderBy('id');
    }
}
