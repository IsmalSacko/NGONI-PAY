<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Argent d'une commande de pressing hors vente : l'acompte versé au dépôt
 * (entre dans la caisse du jour) ou son remboursement à l'annulation (en sort).
 */
#[Fillable(['boutique_id', 'commande_id', 'user_id', 'session_caisse_id', 'type', 'montant', 'moyen_paiement'])]
class EncaissementPressing extends Model
{
    use BelongsToBoutique, HasUuids;

    public const ACOMPTE = 'acompte';

    public const REMBOURSEMENT = 'remboursement';

    protected $table = 'encaissements_pressing';

    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    /** Montant signé : l'acompte entre, le remboursement sort. */
    public function signe(): int
    {
        return $this->type === self::REMBOURSEMENT ? -$this->montant : $this->montant;
    }
}
