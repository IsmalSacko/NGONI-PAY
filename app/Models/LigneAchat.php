<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\QuantiteCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LigneAchat extends Model
{
    use HasUuids;

    protected $table = 'lignes_achat';

    protected $fillable = ['produit_id', 'nom_produit', 'quantite', 'unite', 'contenance', 'numero_lot', 'peremption', 'prix_achat', 'total_ligne'];

    protected function casts(): array
    {
        return ['quantite' => QuantiteCast::class, 'contenance' => 'integer', 'peremption' => 'date:Y-m-d'];
    }
}
