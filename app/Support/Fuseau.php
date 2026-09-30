<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Boutique;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Fuseau horaire d'un pays (code ISO à deux lettres) : celui de sa capitale
 * économique pour un pays qui en a plusieurs (RD Congo : Kinshasa). Inconnu :
 * UTC, l'heure du serveur.
 *
 * Les dates sont enregistrées en UTC ; tout ce qu'un commerçant lit (écran,
 * PDF, export) passe par ici pour être à l'heure de sa boutique.
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

    /** Fuseau de la boutique active (celle de la requête), UTC sans boutique. */
    public static function actif(): string
    {
        $id = app(TenantContext::class)->boutiqueId();

        if ($id === null) {
            return 'UTC';
        }
        // Une requête par boutique, pas une par ligne affichée.
        $cache = app()->has('fuseau.boutiques') ? app('fuseau.boutiques') : [];
        if (! isset($cache[$id])) {
            $cache[$id] = self::pourPays(Boutique::whereKey($id)->value('pays'));
            app()->instance('fuseau.boutiques', $cache);
        }

        return $cache[$id];
    }

    /** `$date` à l'heure de `$pays`, ou de la boutique active sans pays. */
    public static function local(?CarbonInterface $date, ?string $pays = null): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        return Carbon::instance($date->toDateTime())->setTimezone($pays === null ? self::actif() : self::pourPays($pays));
    }

    /** « 30/09/2026 20:05 » à l'heure locale ; vide sans date. */
    public static function heure(?CarbonInterface $date, string $format = 'd/m/Y H:i', ?string $pays = null): string
    {
        return self::local($date, $pays)?->format($format) ?? '';
    }
}
