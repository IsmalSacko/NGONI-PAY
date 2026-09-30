<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * Fuseau horaire d'un pays (code ISO à deux lettres) : celui de sa capitale
 * économique pour un pays qui en a plusieurs (RD Congo : Kinshasa). Inconnu :
 * UTC, l'heure du serveur.
 */
final class Fuseau
{
    /** @var array<string, string> */
    private static array $connus = [];

    public static function pourPays(?string $pays): string
    {
        $code = strtoupper(trim((string) $pays));
        if (strlen($code) !== 2) {
            return 'UTC';
        }

        return self::$connus[$code] ??= DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code)[0] ?? 'UTC';
    }
}
