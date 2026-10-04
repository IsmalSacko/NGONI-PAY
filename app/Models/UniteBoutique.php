<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Unité créée par la boutique : son nom (« tas ») sert de code. */
#[Fillable(['nom', 'pluriel'])]
class UniteBoutique extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'unites';
}
