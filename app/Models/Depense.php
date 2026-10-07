<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dépense de la boutique (loyer, salaires, électricité, fournitures…) : payée
 * en espèces, elle sort du tiroir de la séance de caisse ; le bilan la retire
 * des recettes. Les achats de marchandise, eux, passent par les achats.
 */
#[Fillable(['boutique_id', 'user_id', 'session_caisse_id', 'libelle', 'categorie', 'montant', 'moyen_paiement', 'jour'])]
class Depense extends Model
{
    use BelongsToBoutique, HasUuids;

    public const CATEGORIES = [
        'loyer' => 'Loyer',
        'salaires' => 'Salaires',
        'energie' => 'Eau et électricité',
        'fournitures' => 'Fournitures et consommables',
        'transport' => 'Transport',
        'communication' => 'Téléphone et internet',
        'entretien' => 'Entretien et réparations',
        'taxes' => 'Impôts et taxes',
        'autre' => 'Autre',
    ];

    protected $table = 'depenses';

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
