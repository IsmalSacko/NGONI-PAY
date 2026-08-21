<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Durée souscrite en une fois.
 *
 * Les règlements se font hors application : le commerçant paie d'avance une
 * durée entière, et l'exploitant la constate. L'abonnement était mensuel et
 * seulement mensuel — un commerçant qui voulait régler son année devait revenir
 * douze fois.
 *
 * Le nombre de mois est porté ici, le prix ne l'est pas : il est fixé par
 * l'exploitant, plan par plan et durée par durée ({@see \App\Models\SubscriptionPlanPrice}).
 * Un trimestre n'est donc pas forcément trois fois le mois.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Biannual => 6,
            self::Yearly => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuel',
            self::Quarterly => 'Trimestriel',
            self::Biannual => 'Semestriel',
            self::Yearly => 'Annuel',
        };
    }

    /**
     * Unité pour écrire un prix : « 7 500 F CFA / trimestre ».
     *
     * Sans elle, un tarif trimestriel s'affichait « par mois » et le commerçant
     * comprenait trois fois son prix.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Monthly => 'mois',
            self::Quarterly => 'trimestre',
            self::Biannual => 'semestre',
            self::Yearly => 'an',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $cycle) => $cycle->value, self::cases());
    }
}
