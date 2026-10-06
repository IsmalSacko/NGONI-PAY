<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dépense d'un pressing (lessive, électricité, salaires…) : payée en espèces, elle sort de la caisse du jour. */
#[Fillable(['boutique_id', 'user_id', 'session_caisse_id', 'libelle', 'categorie', 'montant', 'moyen_paiement', 'jour'])]
class DepensePressing extends Model
{
    use BelongsToBoutique, HasUuids;

    public const CATEGORIES = ['fournitures' => 'Fournitures', 'energie' => 'Eau et électricité', 'salaires' => 'Salaires', 'loyer' => 'Loyer', 'transport' => 'Transport', 'autre' => 'Autre'];

    protected $table = 'depenses_pressing';

    protected function casts(): array
    {
        return ['montant' => 'integer', 'jour' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
