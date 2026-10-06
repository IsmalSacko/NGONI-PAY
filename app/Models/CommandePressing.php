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
    'boutique_id', 'numero', 'reference_locale', 'client_id', 'user_id', 'statut', 'express', 'total',
    'acompte', 'moyen_acompte', 'retrait_prevu_le', 'prete_le', 'retiree_le', 'retiree_par', 'vente_id',
    'annulee_le', 'motif_annulation', 'notes',
])]
class CommandePressing extends Model
{
    use BelongsToBoutique, HasUuids;

    public const DEPOSEE = 'deposee';

    public const PRETE = 'prete';

    public const RETIREE = 'retiree';

    public const ANNULEE = 'annulee';

    protected $table = 'commandes_pressing';

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'express' => 'boolean', 'total' => 'integer', 'acompte' => 'integer',
            'retrait_prevu_le' => 'datetime', 'prete_le' => 'datetime', 'retiree_le' => 'datetime', 'annulee_le' => 'datetime',
        ];
    }

    /** « C-0012 ». */
    /** Rendez-vous passé depuis ce nombre de jours : linge considéré comme abandonné. */
    public const JOURS_ABANDON = 30;

    public function numeroLisible(): string
    {
        return 'C-'.str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }

    public function reste(): int
    {
        return max(0, $this->total - $this->acompte);
    }

    public function enRetard(): bool
    {
        return in_array($this->statut, [self::DEPOSEE, self::PRETE], true)
            && $this->retrait_prevu_le !== null && $this->retrait_prevu_le->isPast();
    }

    /** @return HasMany<LigneCommandePressing, $this> */
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
