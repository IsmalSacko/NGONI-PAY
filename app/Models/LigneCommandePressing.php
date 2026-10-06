<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Un type d'habit d'une commande : prestation, quantité, prix figé, défauts. */
#[Fillable(['commande_id', 'produit_id', 'service_id', 'nom', 'service', 'quantite', 'prix_unitaire', 'total_ligne', 'defauts'])]
class LigneCommandePressing extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_commande_pressing';

    protected function casts(): array
    {
        return ['quantite' => 'integer', 'prix_unitaire' => 'integer', 'total_ligne' => 'integer'];
    }
}
