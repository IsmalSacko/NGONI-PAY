<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Durée réglée en une fois. Le prix de chaque durée est fixé par l'exploitant
 * (voir PlanTarif) : un trimestre ne vaut pas forcément trois mois.
 */
enum CycleFacturation: string
{
    case Mensuel = 'monthly';
    case Trimestriel = 'quarterly';
    case Semestriel = 'biannual';
    case Annuel = 'yearly';

    public function mois(): int
    {
        return match ($this) {
            self::Mensuel => 1,
            self::Trimestriel => 3,
            self::Semestriel => 6,
            self::Annuel => 12,
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Mensuel => 'Mensuel',
            self::Trimestriel => 'Trimestriel',
            self::Semestriel => 'Semestriel',
            self::Annuel => 'Annuel',
        };
    }

    /** « 7 500 F / trimestre » */
    public function unite(): string
    {
        return match ($this) {
            self::Mensuel => 'mois',
            self::Trimestriel => 'trimestre',
            self::Semestriel => 'semestre',
            self::Annuel => 'an',
        };
    }
}
