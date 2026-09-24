<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeMouvementStock;
use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\MouvementStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['produit_id', 'user_id', 'vente_id', 'type', 'quantite', 'stock_apres', 'motif'])]
class MouvementStock extends Model
{
    /** @use HasFactory<MouvementStockFactory> */
    use BelongsToBoutique, HasFactory, HasUuids;

    protected $table = 'mouvements_stock';

    protected function casts(): array
    {
        return [
            'type' => TypeMouvementStock::class,
        ];
    }

    /**
     * @return BelongsTo<Produit, $this>
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Vente, $this>
     */
    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }
}
