<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Une table de la salle (« Table 4 », « Terrasse 2 »), avec sa zone et ses places. */
#[Fillable(['boutique_id', 'nom', 'zone', 'places', 'ordre', 'rangee'])]
class TableRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'tables_restaurant';

    protected function casts(): array
    {
        return ['places' => 'integer', 'ordre' => 'integer', 'rangee' => 'boolean'];
    }
}
