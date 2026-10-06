<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Service d'un pressing : « Lavage + repassage », « Repassage seul »… */
#[Fillable(['nom', 'ordre'])]
class ServicePressing extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'services_pressing';

    /** Proposés au passage en pressing ; la boutique les renomme ou en ajoute. */
    public const PAR_DEFAUT = ['Lavage + repassage', 'Repassage seul', 'Lavage seul', 'Nettoyage à sec'];

    protected function casts(): array
    {
        return ['ordre' => 'integer'];
    }
}
