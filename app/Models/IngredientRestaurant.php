<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Ingrédient ou boisson en stock (riz, poulet, huile, bouteilles…), son seuil d'alerte et son coût. */
#[Fillable(['boutique_id', 'nom', 'unite', 'quantite', 'seuil', 'cout_unitaire'])]
class IngredientRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'ingredients_restaurant';

    protected function casts(): array
    {
        return ['quantite' => 'float', 'seuil' => 'float', 'cout_unitaire' => 'integer'];
    }

    public function aRacheter(): bool
    {
        return $this->seuil !== null && $this->quantite <= $this->seuil;
    }
}
