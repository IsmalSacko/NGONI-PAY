<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\QuantiteCast;
use App\Models\Concerns\BelongsToBoutique;
use App\Services\Images;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'categorie_produit_id', 'nom', 'format', 'dci', 'sur_ordonnance', 'unite', 'paliers', 'tarifs', 'code', 'code_barre',
    'prix_achat', 'prix_vente', 'prix_gros', 'seuil_gros', 'taux_tva', 'stock', 'seuil_alerte', 'actif',
])]
class Produit extends Model
{
    /** Adresse de la photo, pour la caisse. */
    protected $appends = ['photo_url', 'vignette_url'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? route('image.produit', ['produit' => $this->id, 'v' => Images::version($this->photo)]) : null;
    }

    /** Photo à la taille d'une tuile de caisse : ce que l'application charge d'abord. */
    public function getVignetteUrlAttribute(): ?string
    {
        return $this->photo ? route('image.produit.vignette', ['produit' => $this->id, 'v' => Images::version($this->photo)]) : null;
    }

    /** @use HasFactory<ProduitFactory> */
    use BelongsToBoutique, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'taux_tva' => 'decimal:2',
            'actif' => 'boolean',
            'stock' => QuantiteCast::class,
            'seuil_alerte' => QuantiteCast::class,
            'seuil_gros' => QuantiteCast::class,
            'sur_ordonnance' => 'boolean',
            'paliers' => 'array',
            'tarifs' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CategorieProduit, $this>
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieProduit::class, 'categorie_produit_id');
    }

    /**
     * @return HasMany<MouvementStock, $this>
     */
    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    /**
     * @return HasMany<Lot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    /**
     * Palier de détail (« boite » : 16 comprimés à 2 800 F), ou null. L'unité
     * de base de l'article est son propre palier : contenance 1, son prix.
     *
     * @return array{unite: string, contenance: int, prix: int, prix_gros: ?int}|null
     */
    public function palier(?string $unite): ?array
    {
        if ($unite === null || $unite === $this->unite) {
            return ['unite' => (string) $this->unite, 'contenance' => 1, 'prix' => (int) $this->prix_vente, 'prix_gros' => $this->prix_gros === null ? null : (int) $this->prix_gros];
        }
        foreach ($this->paliers ?? [] as $p) {
            if (($p['unite'] ?? null) === $unite) {
                return ['unite' => $unite, 'contenance' => (int) $p['contenance'], 'prix' => (int) $p['prix'], 'prix_gros' => isset($p['prix_gros']) ? (int) $p['prix_gros'] : null];
            }
        }

        return null;
    }

    /**
     * Pressing : le prix de cet habit pour un service, express ou non, tel que
     * le pressing l'a fixé. Null : l'habit ne se fait pas dans ce service.
     * Sans prix express écrit, l'express coûte le prix classique.
     */
    public function prixService(string $serviceId, bool $express): ?int
    {
        foreach ($this->tarifs ?? [] as $t) {
            if (($t['service_id'] ?? null) === $serviceId) {
                return (int) ($express && isset($t['prix_express']) ? $t['prix_express'] : $t['prix']);
            }
        }

        return null;
    }

    /**
     * Ce que l'activité de la boutique voit : un pressing, ses tarifs (les
     * habits qui ont des prix par prestation) ; un commerce ou une pharmacie,
     * leur marchandise. Changer d'activité ne supprime rien : en revenant,
     * chacun retrouve les siens.
     *
     * @param  Builder<Produit>  $query
     */
    public function scopePourActivite(Builder $query, ?Boutique $boutique = null): void
    {
        $boutique ??= Boutique::find(app(TenantContext::class)->boutiqueId());
        if ($boutique?->estPressing()) {
            $query->whereNotNull('tarifs');
        } else {
            $query->whereNull('tarifs');
        }
    }

    public function estEnRupture(): bool
    {
        return $this->stock <= 0;
    }

    public function stockFaible(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->seuil_alerte;
    }
}
