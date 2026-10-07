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
    'etat', 'envoi', 'envoyee_le', 'prete_le', 'servie_le', 'vente_id', 'ordre',
])]
class LigneCommandeRestaurant extends Model
{
    use HasUuids;

    public const ATTENTE = 'attente';

    public const EN_CUISINE = 'en_cuisine';

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
