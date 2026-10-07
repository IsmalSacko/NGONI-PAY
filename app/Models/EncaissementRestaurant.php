<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Argent d'une commande de restaurant hors vente : l'acompte d'une commande
 * par téléphone (entre dans la caisse du jour), son remboursement (en sort),
 * le pourboire laissé à l'addition.
 */
#[Fillable(['boutique_id', 'commande_id', 'user_id', 'session_caisse_id', 'type', 'montant', 'moyen_paiement'])]
class EncaissementRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    public const ACOMPTE = 'acompte';

    public const REMBOURSEMENT = 'remboursement';

    public const POURBOIRE = 'pourboire';

    protected $table = 'encaissements_restaurant';

    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    /** Montant signé : l'acompte et le pourboire entrent, le remboursement sort. */
    public function signe(): int
    {
        return $this->type === self::REMBOURSEMENT ? -$this->montant : $this->montant;
    }
}
