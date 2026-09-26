<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\VenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'client_id', 'session_caisse_id', 'reference_locale', 'numero', 'sous_total', 'remise',
    'tva', 'total', 'moyen_paiement', 'montant_recu', 'monnaie_rendue', 'statut',
    'vendue_hors_ligne', 'synchronisee_le', 'annulee_le', 'annulee_par', 'motif_annulation',
])]
class Vente extends Model
{
    /** @use HasFactory<VenteFactory> */
    use BelongsToBoutique, HasFactory, HasUuids;

    /** Numéro lisible, envoyé à l'application avec chaque vente. */
    protected $appends = ['numero_facture'];

    /** @var array<string, string> préfixe par boutique, le temps d'une requête */
    private static array $prefixes = [];

    protected function casts(): array
    {
        return [
            'moyen_paiement' => MoyenPaiement::class,
            'vendue_hors_ligne' => 'boolean',
            'synchronisee_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
     * la vente dans la boutique. Unique et continu par boutique.
     */
    public function numeroFormate(): string
    {
        return self::prefixe($this->boutique_id).'-'.($this->created_at ?? now())->format('Y')
            .'-'.str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }

    public function getNumeroFactureAttribute(): string
    {
        return $this->numeroFormate();
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
}
