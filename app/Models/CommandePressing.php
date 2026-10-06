<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande d'un pressing (le dépôt) : créée au dépôt, prête, puis clôturée au
 * retrait — c'est là que naît la vente. Les prix sont figés au dépôt.
 */
#[Fillable([
    'boutique_id', 'numero', 'reference_locale', 'client_id', 'forfait_id', 'user_id', 'statut', 'etape', 'historique', 'express', 'collecte', 'livraison', 'adresse', 'casier', 'photos', 'total',
    'acompte', 'moyen_acompte', 'retrait_prevu_le', 'prete_le', 'retiree_le', 'retiree_par', 'vente_id',
    'annulee_le', 'motif_annulation', 'notes',
])]
class CommandePressing extends Model
{
    use BelongsToBoutique, HasUuids;

    public const DEPOSEE = 'deposee';

    /** Au travail : l'étape en cours est dans `etape`. */
    public const EN_TRAITEMENT = 'en_traitement';

    public const PRETE = 'prete';

    public const RETIREE = 'retiree';

    public const ANNULEE = 'annulee';

    /** Photos de défauts gardées par commande. */
    public const PHOTOS_MAX = 6;

    /** Les commandes pas encore rendues. */
    public const OUVERTES = [self::DEPOSEE, self::EN_TRAITEMENT, self::PRETE];

    /** Les étapes du travail, dans l'ordre. Facultatives : on peut passer directement à « prête ». */
    public const ETAPES = ['lavage' => 'Lavage', 'sechage' => 'Séchage', 'repassage' => 'Repassage', 'controle' => 'Contrôle qualité'];

    protected $table = 'commandes_pressing';

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'express' => 'boolean', 'total' => 'integer', 'acompte' => 'integer',
            'historique' => 'array', 'photos' => 'array', 'collecte' => 'boolean', 'livraison' => 'boolean', 'retrait_prevu_le' => 'datetime', 'prete_le' => 'datetime', 'retiree_le' => 'datetime', 'annulee_le' => 'datetime',
        ];
    }

    /**
     * Ajoute un pas à l'historique : quoi, quand, par qui.
     *
     * @return list<array{quoi: string, le: string, par: ?string}>
     */
    public function avecPas(string $quoi, ?User $par): array
    {
        return [...($this->historique ?? []), ['quoi' => $quoi, 'le' => now()->toIso8601String(), 'par' => $par?->name]];
    }

    /** Rendez-vous passé depuis ce nombre de jours : linge considéré comme abandonné. */
    public const JOURS_ABANDON = 30;

    /** « 482913 » : 6 chiffres tirés au hasard au dépôt. */
    public function numeroLisible(): string
    {
        return (string) $this->numero;
    }

    public function reste(): int
    {
        return max(0, $this->total - $this->acompte);
    }

    public function enRetard(): bool
    {
        return in_array($this->statut, self::OUVERTES, true)
            && $this->retrait_prevu_le !== null && $this->retrait_prevu_le->isPast();
    }

    /** @return HasMany<LigneCommandePressing, $this> */
    /** @return HasMany<EncaissementPressing, $this> */
    public function encaissements(): HasMany
    {
        return $this->hasMany(EncaissementPressing::class, 'commande_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandePressing::class, 'commande_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
