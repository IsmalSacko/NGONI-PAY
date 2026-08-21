<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'une demande d'abonnement.
 *
 * Une demande n'ouvre aucun accès : c'est la décision de l'exploitant qui le
 * fait. Tant qu'elle est `pending`, le business reste sur son plan actuel.
 */
enum SubscriptionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Approuvée',
            self::Refused => 'Refusée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Une demande déjà tranchée ne se retranche pas : deux approbations
     * accorderaient deux mois pour un seul paiement.
     */
    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
