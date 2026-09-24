<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Moyens de paiement acceptés à la caisse tactile. `MobileMoney` regroupe les
 * opérateurs (Orange Money, Moov Money, Wave...) : la marque exacte n'importe
 * pas au modèle de données, seulement au ticket affiché au client.
 */
enum MoyenPaiement: string
{
    case Especes = 'especes';
    case OrangeMoney = 'orange_money';
    case MoovMoney = 'moov_money';
    case Wave = 'wave';
    case Carte = 'carte';
    case CreditClient = 'credit_client';

    public function label(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Wave => 'Wave',
            self::Carte => 'Carte',
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
