<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un mouvement du journal de stock — voir MouvementStock.
 */
enum TypeMouvementStock: string
{
    case Entree = 'entree';
    case Sortie = 'sortie';
    case Ajustement = 'ajustement';

    public function label(): string
    {
        return match ($this) {
            self::Entree => 'Entrée',
            self::Sortie => 'Sortie',
            self::Ajustement => 'Ajustement',
        };
    }
}
