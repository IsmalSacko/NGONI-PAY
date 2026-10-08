<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleFacturation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan d'abonnement, tenu par l'exploitant depuis la console : nom, limites,
 * tarifs par durée. `essai` est offert à l'inscription et ne se vend pas.
 */
class Plan extends Model
{
    public const ESSAI = 'essai';

    /** Séances de caisse (fond d'ouverture, clôture, écart) : réservées au Pro. */
    public const SEANCES_CAISSE = 'seances_caisse';

    /** Affluence, marge par article, stock dormant, clients, équipe : réservées au Pro. */
    public const STATISTIQUES_AVANCEES = 'statistiques_avancees';

    /** Vendre contre une dette du client (moyen « crédit client »). */
    public const VENTE_CREDIT = 'vente_credit';

    /** Gérer la boutique depuis un ordinateur. */
    public const BACKOFFICE_WEB = 'backoffice_web';

    /** Réceptions de marchandise, fournisseurs et ce qu'on leur doit. */
    public const ACHATS_FOURNISSEURS = 'achats_fournisseurs';

    /** Programme de fidélité : points et remise automatique. */
    public const FIDELITE = 'fidelite';

    /** Interrupteurs par membre (chiffre d'affaires, remises, crédit…). Sans eux : ceux du rôle. */
    public const DROITS_MEMBRES = 'droits_membres';

    /** Factures A4, exports Excel et bilan mensuel en PDF. */
    public const FACTURES_EXPORTS = 'factures_exports';

    /** Objectif de chiffre du mois et sa jauge. */
    public const OBJECTIF_MOIS = 'objectif_mois';

    /** Deux prix par article, détail et gros (réglage « Vos ventes »). */
    public const VENTE_GROS = 'vente_gros';

    /**
     * Ancienne fonction du pressing : tout le pressing est désormais dans tous
     * les plans. Toujours annoncée comme incluse, pour les apps ≤ 4.10.10 qui
     * mettaient un cadenas sur l'express sans elle.
     */
    public const PRESSING_AVANCE = 'pressing_avance';

    /** Dépenses de la boutique et bilan (recettes − dépenses). */
    public const DEPENSES = 'depenses';

    /**
     * Restaurant : écran cuisine et bar, réservations, livraison, commandes
     * différées, options et formules, ingrédients et recettes, bilan du
     * service. Le reste du restaurant (commandes, salle, addition) est pour tous.
     */
    public const RESTAURANT_AVANCE = 'restaurant_avance';

    /**
     * Fonctions qu'un plan inclut ou non, cochées dans la console. Le reste
     * (caisse, catalogue, stocks, clients, tickets…) est dans tous les plans.
     */
    public const FONCTIONNALITES = [
        // Libellés courts : la vitrine et le comparatif de l'application les
        // affichent tels quels, à côté d'une coche.
        self::VENTE_CREDIT => 'Vente à crédit',
        self::BACKOFFICE_WEB => 'Back-office web',
        self::SEANCES_CAISSE => 'Séances de caisse et écarts',
        self::STATISTIQUES_AVANCEES => 'Statistiques avancées',
        self::ACHATS_FOURNISSEURS => 'Achats et fournisseurs',
        self::FIDELITE => 'Programme de fidélité',
        self::DROITS_MEMBRES => 'Droits par membre de l’équipe',
        self::FACTURES_EXPORTS => 'Factures A4, exports, bilan mensuel',
        self::OBJECTIF_MOIS => 'Objectif du mois',
        self::VENTE_GROS => 'Vente en gros (deux prix)',
        self::DEPENSES => 'Dépenses et bilan',
        self::RESTAURANT_AVANCE => 'Restaurant : cuisine, réservations, livraison, recettes',
    ];

    /** Dans tous les plans, sans case dans la console : la base de la caisse. */
    public const COMMUNES = [
        'Caisse, tickets et reçus PDF',
        'Catalogue, stocks et clients',
        'Ventes hors ligne',
    ];

    protected $fillable = [
        'code', 'nom', 'description', 'fonctionnalites', 'jours_essai',
        'max_boutiques', 'max_membres', 'est_actif', 'ordre',
        'prix_a_vie', 'texte_a_vie', 'a_vie_debut', 'a_vie_fin',
    ];

    protected function casts(): array
    {
        return [
            'fonctionnalites' => 'array',
            'jours_essai' => 'integer',
            'max_boutiques' => 'integer',
            'max_membres' => 'integer',
            'prix_a_vie' => 'integer',
            'a_vie_debut' => 'date',
            'a_vie_fin' => 'date',
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
        return [...array_values(array_filter(array_keys(self::FONCTIONNALITES), fn (string $f) => $this->inclut($f))), self::PRESSING_AVANCE];
    }

    /**
     * Abonnement à vie proposé aujourd'hui : les plans qui l'offrent (nom, prix,
     * texte, boutiques) et la période de l'offre. Null s'il n'est pas proposé.
     *
     * @param  iterable<Plan>|null  $plans
     * @return array{offres: list<array{nom: string, prix: int, texte: ?string, max_boutiques: ?int}>, debut: ?Carbon, fin: ?Carbon}|null
     */
    public static function offreAVie(?iterable $plans = null): ?array
    {
        $ouverts = collect($plans ?? self::actifs()->ordonnes()->get())->filter(fn (Plan $p) => ! $p->estEssai() && $p->aVieOuverte())->values();
        if ($ouverts->isEmpty()) {
            return null;
        }

        return [
            'offres' => $ouverts->map(fn (Plan $p) => [
                'nom' => $p->nom, 'prix' => (int) $p->prix_a_vie, 'texte' => $p->texte_a_vie, 'max_boutiques' => $p->max_boutiques,
            ])->all(),
            'debut' => $ouverts->pluck('a_vie_debut')->filter()->min(),
            'fin' => $ouverts->pluck('a_vie_fin')->filter()->max(),
        ];
    }

    /** L'offre à vie de ce plan est proposée aujourd'hui (prix fixé, dans sa période). */
    public function aVieOuverte(): bool
    {
        return $this->prix_a_vie !== null && $this->prix_a_vie > 0
            && ($this->a_vie_debut === null || $this->a_vie_debut->lte(today()))
            && ($this->a_vie_fin === null || $this->a_vie_fin->gte(today()));
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
