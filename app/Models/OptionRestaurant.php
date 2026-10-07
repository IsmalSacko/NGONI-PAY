<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Option d'un plat (cuisson, accompagnement, supplément), avec son prix (0 :
 * gratuite). Dans un groupe à [choix_unique], on en prend une seule (portion,
 * cuisson) ; un groupe [obligatoire] doit être choisi.
 */
#[Fillable(['boutique_id', 'produit_id', 'groupe', 'nom', 'prix', 'choix_unique', 'obligatoire', 'ordre'])]
class OptionRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'options_restaurant';

    protected function casts(): array
    {
        return ['prix' => 'integer', 'ordre' => 'integer', 'choix_unique' => 'boolean', 'obligatoire' => 'boolean'];
    }
}
