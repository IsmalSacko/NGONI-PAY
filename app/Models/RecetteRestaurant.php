<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ce qu'un plat consomme d'un ingrédient (200 g de riz pour un riz sauce). */
#[Fillable(['boutique_id', 'produit_id', 'ingredient_id', 'quantite'])]
class RecetteRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'recettes_restaurant';

    protected function casts(): array
    {
        return ['quantite' => 'float'];
    }

    /** @return BelongsTo<IngredientRestaurant, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(IngredientRestaurant::class, 'ingredient_id');
    }
}
