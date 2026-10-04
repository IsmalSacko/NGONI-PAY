<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\QuantiteCast;
use App\Models\Concerns\BelongsToBoutique;
use App\Services\Lots;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot d'un médicament : son numéro, sa date de péremption et ce qui en
 * reste, en unités de base (comprimés). Voir {@see Lots}.
 */
#[Fillable(['produit_id', 'numero', 'peremption', 'quantite_initiale', 'quantite', 'achat_id'])]
class Lot extends Model
{
    use BelongsToBoutique, HasUuids;

    protected function casts(): array
    {
        return [
            'peremption' => 'date:Y-m-d',
            'quantite_initiale' => QuantiteCast::class,
            'quantite' => QuantiteCast::class,
        ];
    }

    /**
     * @return BelongsTo<Produit, $this>
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
