<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Fourniture d'un pressing (lessive, cintres, housses…) : ce qui reste, et le seuil d'alerte. */
#[Fillable(['boutique_id', 'nom', 'unite', 'quantite', 'seuil'])]
class FourniturePressing extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'fournitures_pressing';

    protected function casts(): array
    {
        return ['quantite' => 'float', 'seuil' => 'float'];
    }

    public function aRacheter(): bool
    {
        return $this->seuil !== null && $this->quantite <= $this->seuil;
    }
}
