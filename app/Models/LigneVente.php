<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\QuantiteCast;
use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\LigneVenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une vente. Pas de {@see BelongsToBoutique} :
 * elle appartient à une {@see Vente}, déjà scopée par boutique, et n'a pas
 * besoin d'un second filtre tenant.
 */
#[Fillable(['produit_id', 'nom_produit', 'prix_unitaire', 'prix_gros', 'prix_detail', 'prix_achat', 'taux_tva', 'quantite', 'unite', 'contenance', 'lots', 'total_ligne', 'service', 'express'])]
class LigneVente extends Model
{
    /** @use HasFactory<LigneVenteFactory> */
    use HasFactory, HasUuids;

    protected $table = 'lignes_vente';

    protected function casts(): array
    {
        return [
            'taux_tva' => 'decimal:2',
            'quantite' => QuantiteCast::class,
            'contenance' => 'integer',
            'lots' => 'array',
            'prix_gros' => 'boolean',
            'express' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vente, $this>
     */
    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    /**
     * @return BelongsTo<Produit, $this>
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
