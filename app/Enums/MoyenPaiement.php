<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Moyens de paiement acceptés à la caisse tactile.
 *
 * Purement déclaratifs : aucun n'appelle d'API de paiement. Le caissier
 * encaisse (terminal de carte, application PayPal, téléphone du client...) puis
 * enregistre le moyen utilisé ; e-caisse ne débite rien et ne vérifie pas qu'un
 * paiement a réellement abouti. La valeur `carte` est conservée telle quelle
 * (libellé « Carte bancaire ») pour ne pas migrer les ventes déjà enregistrées.
 */
enum MoyenPaiement: string
{
    case Especes = 'especes';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Wave = 'wave';
    case Carte = 'carte';
    case PayPal = 'paypal';
    case CreditClient = 'credit_client';

    public function label(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Wave => 'Wave',
            self::Carte => 'Carte bancaire',
            self::PayPal => 'PayPal',
            self::CreditClient => 'Crédit client',
        };
    }

    public function estMobileMoney(): bool
    {
        return match ($this) {
            self::OrangeMoney, self::MoovMoney, self::Wave => true,
            default => false,
        };
    }
}
