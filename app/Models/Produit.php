<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'categorie_produit_id', 'nom', 'format', 'code', 'code_barre',
    'prix_achat', 'prix_vente', 'taux_tva', 'stock', 'seuil_alerte', 'actif',
])]
class Produit extends Model
{
    /** @use HasFactory<ProduitFactory> */
    use BelongsToBoutique, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'taux_tva' => 'decimal:2',
            'actif' => 'boolean',
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

    public function estEnRupture(): bool
    {
        return $this->stock <= 0;
    }

    public function stockFaible(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->seuil_alerte;
    }
}
