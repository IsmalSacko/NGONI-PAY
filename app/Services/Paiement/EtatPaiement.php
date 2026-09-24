<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\StatutPaiement;

/**
 * État d'un paiement tel que le fournisseur le rapporte, ramené au
 * vocabulaire d'e-caisse.
 */
final readonly class EtatPaiement
{
    public function __construct(public StatutPaiement $statut, public ?string $detail = null) {}
}
