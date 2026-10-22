<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un coffre : une partie de la recette mise de côté (« Loyer », « Tabaski »).
 * Ngoni ne détient pas l'argent : le solde est ce que le commerçant a mis de
 * côté d'après ses mouvements (ajouts, versements du soir, retraits).
 */
#[Fillable(['boutique_id', 'nom', 'mode', 'montant_fixe', 'pourcentage', 'regle_depuis'])]
class Coffre extends Model
{
    use BelongsToBoutique, HasUuids;

    /** Seulement à la main. */
    public const MANUEL = 'manuel';

    /** Une somme chaque jour où la boutique a encaissé. */
    public const FIXE = 'fixe';

    /** Une part de ce qui a été encaissé dans la journée. */
    public const POURCENTAGE = 'pourcentage';

    public const MODES = [self::MANUEL, self::FIXE, self::POURCENTAGE];

    protected $table = 'coffres';

    protected function casts(): array
    {
        return ['montant_fixe' => 'integer', 'pourcentage' => 'float', 'regle_depuis' => 'date'];
    }

    /** @return HasMany<MouvementCoffre, $this> */
    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementCoffre::class);
    }

    public function estAutomatique(): bool
    {
        return $this->mode !== self::MANUEL;
    }
}
