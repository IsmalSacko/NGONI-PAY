<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Réservation : qui, quand, combien de couverts, quelle table. À l'arrivée, elle ouvre la commande. */
#[Fillable(['boutique_id', 'client_id', 'nom', 'telephone', 'le', 'couverts', 'table', 'note', 'statut', 'commande_id'])]
class ReservationRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    public const PREVUE = 'prevue';

    public const ARRIVEE = 'arrivee';

    public const ANNULEE = 'annulee';

    protected $table = 'reservations_restaurant';

    protected function casts(): array
    {
        return ['le' => 'datetime', 'couverts' => 'integer'];
    }
}
