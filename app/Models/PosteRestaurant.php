<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Restaurant : le poste qui prépare une catégorie (la cuisine, ou le bar pour les boissons). */
#[Fillable(['boutique_id', 'categorie_produit_id', 'poste'])]
class PosteRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    public const CUISINE = 'cuisine';

    public const BAR = 'bar';

    protected $table = 'postes_restaurant';
}
