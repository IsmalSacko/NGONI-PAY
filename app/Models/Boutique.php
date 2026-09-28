<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use App\Services\Images;
use Database\Factories\BoutiqueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Racine du multi-tenant : un commerçant qui utilise e-caisse.
 *
 * Entité racine — n'utilise pas {@see BelongsToBoutique}
 * pour la même raison que {@see User} : elle EST le tenant, elle ne lui
 * appartient pas.
 */
#[Fillable(['proprietaire_id', 'nom', 'pays', 'devise', 'telephone', 'email', 'adresse', 'logo', 'identifiant_fiscal', 'rccm', 'message_ticket'])]
class Boutique extends Model
{
    /** Adresse du logo, pour l'application et les tickets. */
    protected $appends = ['logo_url', 'logo_vignette_url'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? route('image.logo', ['boutique' => $this->id, 'v' => Images::version($this->logo)]) : null;
    }

    /** Logo réduit, pour l'affichage à l'écran ; l'impression garde la taille réelle. */
    public function getLogoVignetteUrlAttribute(): ?string
    {
        return $this->logo ? route('image.logo.vignette', ['boutique' => $this->id, 'v' => Images::version($this->logo)]) : null;
    }

    /** @use HasFactory<BoutiqueFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Compte propriétaire : il porte l'abonnement de toutes ses boutiques.
     *
     * @return BelongsTo<User, $this>
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietaire_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<CategorieProduit, $this>
     */
    public function categoriesProduits(): HasMany
    {
        return $this->hasMany(CategorieProduit::class);
    }

    /**
     * @return HasMany<Produit, $this>
     */
    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Vente, $this>
     */
    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }
}
