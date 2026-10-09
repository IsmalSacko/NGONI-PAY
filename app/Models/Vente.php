<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Models\Concerns\BelongsToBoutique;
use App\Services\NumerotationFactures;
use Database\Factories\VenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'client_id', 'session_caisse_id', 'reference_locale', 'numero', 'sous_total', 'remise',
    'tva', 'total', 'montant_paye', 'acompte_deduit', 'commande_restaurant_id', 'paiements', 'reste_du', 'moyen_paiement', 'montant_recu', 'monnaie_rendue', 'statut',
    'vendue_hors_ligne', 'synchronisee_le', 'annulee_le', 'annulee_par', 'motif_annulation',
    'jour_affaire', 'numero_jour', 'remise_fidelite', 'numero_facture', 'ordonnance', 'tarif', 'express',
])]
class Vente extends Model
{
    /** @use HasFactory<VenteFactory> */
    use BelongsToBoutique, HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Filet : une vente créée hors du service de caisse compte pour le
        // jour de sa création.
        static::creating(function (Vente $vente): void {
            $vente->jour_affaire ??= ($vente->created_at ?? now())->toDateString();
            // Numéro de facture figé à la création : renommer la boutique
            // ensuite ne change plus celui des factures déjà remises.
            $vente->attributes['numero_facture'] ??= self::prochainNumeroFacture($vente);
            // Mode rodage : une vente d'essai, numérotée ESSAI-…, supprimable.
            if ($vente->boutique_id !== null && ! isset($vente->attributes['essai'])) {
                $vente->attributes['essai'] = (bool) Boutique::withoutGlobalScopes()->whereKey($vente->boutique_id)->value('mode_rodage');
            }
        });

        // Le compteur de la boutique ne recule jamais (voir dernier_numero_vente) :
        // aussi pour une vente créée hors du service de caisse (import).
        static::created(function (Vente $vente): void {
            Boutique::withoutGlobalScopes()->whereKey($vente->boutique_id)
                ->where('dernier_numero_vente', '<', (int) $vente->numero)
                ->update(['dernier_numero_vente' => (int) $vente->numero]);
        });
    }

    /** Numéro lisible, envoyé à l'application avec chaque vente. */
    protected $appends = ['numero_facture'];

    /** @var array<string, string> préfixe par boutique, le temps d'une requête */
    private static array $prefixes = [];

    protected function casts(): array
    {
        return [
            'moyen_paiement' => MoyenPaiement::class,
            'essai' => 'boolean',
            'vendue_hors_ligne' => 'boolean',
            'remise_fidelite' => 'boolean',
            'ordonnance' => 'array',
            'paiements' => 'array',
            'express' => 'boolean',
            'synchronisee_le' => 'datetime',
            'annulee_le' => 'datetime',
            'jour_affaire' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caissier(): BelongsTo
    {
        // Un compte supprimé garde son nom sur les tickets, factures et historiques.
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<SessionCaisse, $this>
     */
    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    /**
     * @return HasMany<LigneVente, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneVente::class);
    }

    public const STATUT_VALIDEE = 'validee';

    /** Annulée (reprise de l'historique Ngoni Pay) : gardée, jamais comptée. */
    public const STATUT_ANNULEE = 'annulee';

    public function estAnnulee(): bool
    {
        return $this->statut === self::STATUT_ANNULEE;
    }

    /** Ventes qui comptent dans les chiffres (recettes, écarts de caisse). */
    public function scopeValides($query)
    {
        return $query->where($this->getTable().'.statut', self::STATUT_VALIDEE);
    }

    /**
     * « PLC-2026-0003 » : initiales de la boutique, année de la vente, rang de
     * la vente dans la boutique. Unique et continu par boutique, et figé : le
     * numéro enregistré à la création, jamais recalculé.
     */
    public function numeroFormate(): string
    {
        return $this->attributes['numero_facture'] ?? self::formater($this->boutique_id, (int) $this->numero, $this->created_at);
    }

    public function getNumeroFactureAttribute(): string
    {
        return $this->numeroFormate();
    }

    /**
     * Numéro de facture d'une nouvelle vente : compteur de la boutique pour
     * l'année, qui repart à 1 chaque 1er janvier (ABC-2027-0001) et ne recule
     * jamais dans l'année — même après « Repartir de zéro », un numéro remis à
     * un client ne resert pas. Le service de caisse tient déjà la boutique
     * verrouillée pendant la vente.
     */
    private static function prochainNumeroFacture(Vente $vente): string
    {
        if ($vente->boutique_id === null) {
            return self::formater(null, (int) $vente->numero, $vente->created_at);
        }

        return app(NumerotationFactures::class)->prendre($vente->boutique_id, $vente->created_at);
    }

    /** Le numéro tel qu'il est calculé une seule fois, à la création de la vente. */
    public static function formater(?string $boutiqueId, int $numero, mixed $creeLe): string
    {
        $annee = ($creeLe === null ? now() : Carbon::parse($creeLe))->format('Y');

        return self::prefixe($boutiqueId).'-'.$annee.'-'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    /** « PHARMACIE "LES CASTORS" » → PLC ; « OIL » → OIL ; « @wisdomofficiel » → WIS. */
    public static function prefixe(?string $boutiqueId): string
    {
        if ($boutiqueId === null) {
            return 'EC';
        }

        return self::$prefixes[$boutiqueId] ??= self::initiales(
            (string) Boutique::withoutGlobalScopes()->whereKey($boutiqueId)->value('nom'),
        );
    }

    public static function initiales(string $nom): string
    {
        $mots = preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii($nom)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // « de », « la », « du » ne comptent pas : Boutique de la Paix → BP.
        $mots = array_values(array_filter($mots, fn (string $m) => strlen($m) > 2)) ?: $mots;

        $initiales = count($mots) >= 2
            ? implode('', array_map(fn (string $m) => $m[0], array_slice($mots, 0, 3)))
            : substr($mots[0] ?? 'EC', 0, 3);

        return $initiales !== '' ? $initiales : 'EC';
    }

    /** Restaurant : la commande réglée par cette vente. */
    public function commandeRestaurant(): BelongsTo
    {
        return $this->belongsTo(CommandeRestaurant::class);
    }

    /**
     * Ce que la vente a reçu, par moyen : le moyen de la vente pour tout,
     * sauf un paiement mixte (restaurant), réparti selon ses parts — le
     * reste (acompte déjà compté) reste sur le moyen de la vente.
     *
     * @return array<string, int>
     */
    public function partsParMoyen(int $montant): array
    {
        $principal = $this->moyen_paiement instanceof MoyenPaiement ? $this->moyen_paiement->value : (string) $this->moyen_paiement;
        if (empty($this->paiements)) {
            return [$principal => $montant];
        }
        $parts = [];
        foreach ($this->paiements as $p) {
            $parts[$p['moyen']] = ($parts[$p['moyen']] ?? 0) + (int) $p['montant'];
        }
        $parts[$principal] = ($parts[$principal] ?? 0) + $montant - array_sum($parts);

        return $parts;
    }
}
