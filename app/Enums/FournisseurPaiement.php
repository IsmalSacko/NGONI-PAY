<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fournisseur de paiement en ligne (couche optionnelle, voir
 * config/paiements.php). Distinct de {@see MoyenPaiement} : le moyen est ce
 * que le client a utilisé, le fournisseur est la passerelle qui l'a encaissé.
 */
enum FournisseurPaiement: string
{
    case PayPal = 'paypal';
    case PayDunya = 'paydunya';

    public function label(): string
    {
        return match ($this) {
            self::PayPal => 'PayPal',
            self::PayDunya => 'PayDunya',
        };
    }
}
