<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande d'un commerçant (revendeur) à son fournisseur. Rattachée à la
 * boutique du revendeur ; le grossiste la lit hors de ce filtre, par
 * `boutique_grossiste_id`, quand il tient lui aussi une boutique Ngoni.
 */
class CommandeFournisseur extends Model
{
    use BelongsToBoutique, HasUuids;

    public const STATUTS = [
        'envoyee' => 'Envoyée',
        'confirmee' => 'Confirmée',
        'en_livraison' => 'En livraison',
        'livree' => 'Livrée',
        'refusee' => 'Refusée',
        'annulee' => 'Annulée',
    ];

    protected $table = 'commandes_fournisseur';

    protected $fillable = [
        'boutique_id', 'fournisseur_id', 'boutique_grossiste_id', 'numero', 'statut', 'note', 'motif',
        'cree_par', 'total_estime', 'vente_id', 'achat_id', 'vue_le', 'confirmee_le', 'expediee_le', 'livree_le',
    ];

    protected function casts(): array
    {
        return ['total_estime' => 'integer', 'vue_le' => 'datetime', 'confirmee_le' => 'datetime', 'expediee_le' => 'datetime', 'livree_le' => 'datetime'];
    }

    /** @return HasMany<LigneCommandeFournisseur, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeFournisseur::class, 'commande_id');
    }

    /** @return BelongsTo<Fournisseur, $this> */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Boutique, $this> */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Boutique, $this> */
    public function boutiqueGrossiste(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_grossiste_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function ouverte(): bool
    {
        return in_array($this->statut, ['envoyee', 'confirmee'], true);
    }
}
