<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un plat ou une boisson d'une commande, suivi en cuisine : attente → en cuisine → prête → servie. */
#[Fillable([
    'commande_id', 'produit_id', 'nom', 'quantite', 'prix_unitaire', 'total_ligne', 'options', 'composition', 'note',
    'poste', 'etat', 'envoi', 'envoyee_le', 'prete_le', 'servie_le', 'vente_id', 'ordre',
])]
class LigneCommandeRestaurant extends Model
{
    use HasUuids;

    public const ATTENTE = 'attente';

    /** Envoyé, à préparer. */
    public const EN_CUISINE = 'en_cuisine';

    /** Le cuisinier (ou le barman) s'y est mis. */
    public const EN_PREPARATION = 'en_preparation';

    /** Pas encore prêts : à préparer ou en préparation. */
    public const EN_COURS = [self::EN_CUISINE, self::EN_PREPARATION];

    public const PRETE = 'prete';

    public const SERVIE = 'servie';

    public const ANNULEE = 'annulee';

    public $timestamps = false;

    protected $table = 'lignes_commande_restaurant';

    protected function casts(): array
    {
        return [
            'quantite' => 'integer', 'prix_unitaire' => 'integer', 'total_ligne' => 'integer', 'options' => 'array', 'composition' => 'array',
            'envoi' => 'integer', 'envoyee_le' => 'datetime', 'prete_le' => 'datetime', 'servie_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<CommandeRestaurant, $this> */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeRestaurant::class, 'commande_id');
    }
}
