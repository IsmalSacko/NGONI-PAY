<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Adresses de la Console Ngoni, commune à toutes les applications IsmaelDev :
 * c'est là, et plus dans la console de l'application, que se gèrent les
 * abonnements (demandes, paiements, formules, accords).
 */
final class ConsoleNgoni
{
    public static function lien(string $chemin = ''): string
    {
        return rtrim((string) config('services.console_ngoni.url'), '/').'/'.ltrim($chemin, '/');
    }

    /** Les demandes d'abonnement de Ngoni Caisse. */
    public static function demandes(): string
    {
        return self::lien('demandes?app=ngoni-caisse');
    }
}
