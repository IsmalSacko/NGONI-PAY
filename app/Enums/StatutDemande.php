<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Une demande n'ouvre aucun accès : c'est la décision de l'exploitant qui le fait.
 */
enum StatutDemande: string
{
    case EnAttente = 'en_attente';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
        };
    }

    /** Une demande tranchée ne se retranche pas : deux approbations donneraient deux périodes. */
    public function estTranchee(): bool
    {
        return $this !== self::EnAttente;
    }
}
