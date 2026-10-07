<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Formule (menu du midi…) : un article de la carte à prix fixe, composé
 * d'étapes (« Entrée », « Plat », « Boisson ») où l'on choisit parmi des plats.
 */
#[Fillable(['boutique_id', 'produit_id', 'etapes'])]
class FormuleRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'formules_restaurant';

    protected function casts(): array
    {
        return ['etapes' => 'array'];
    }
}
