<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Annonce;
use App\Services\ConditionsUtilisation;

/**
 * Dernière version publiée de l'application : la plus haute entre celle du
 * .env (MOBILE_LATEST_VERSION) et celles déjà annoncées aux commerçants.
 *
 * Une version publiée sur le Play Store est annoncée d'elle-même (voir
 * PublicationPlayController) : elle devient aussitôt la version proposée en
 * mise à jour par l'application, sans toucher au .env.
 */
class VersionApplication
{
    public static function derniere(): string
    {
        $configuree = (string) config('mobile.latest_version');

        // Pages publiques (téléchargement, vitrine) : une base indisponible ne
        // doit pas les faire tomber, la version du .env suffit alors.
        try {
            $annoncees = Annonce::where('type', 'mise_a_jour')->where('statut', 'envoyee')->whereNotNull('version')->pluck('version')->all();
        } catch (\Throwable) {
            return $configuree;
        }

        return self::plusHaute([...$annoncees, $configuree]) ?? $configuree;
    }

    /**
     * Version en deçà de laquelle l'application exige la mise à jour.
     *
     * MOBILE_MINIMUM_VERSION dit celle que l'on veut imposer ; elle ne
     * s'applique qu'une fois disponible partout : vue en production sur le
     * Play Store (et annoncée) depuis MOBILE_DELAI_VERSION_MINIMALE_JOURS. Avant,
     * un commerçant à qui le Play Store ne la propose pas encore serait bloqué.
     * Le socle reste la version qui présente les conditions d'utilisation.
     */
    public static function minimale(): string
    {
        $socle = version_compare(self::derniere(), ConditionsUtilisation::VERSION_APPLICATION, '>=')
            ? ConditionsUtilisation::VERSION_APPLICATION
            : '0.0.0';
        $voulue = (string) config('mobile.minimum_version');

        return self::plusHaute([$socle, self::disponiblePartout($voulue) ? $voulue : '0.0.0']) ?? $socle;
    }

    /**
     * Cette version est publiée : déclarée dans le .env (MOBILE_LATEST_VERSION),
     * ou vue en production sur le Play Store et annoncée depuis le délai voulu.
     */
    private static function disponiblePartout(string $version): bool
    {
        if (version_compare($version, (string) config('mobile.latest_version'), '<=')) {
            return true;
        }

        $jours = (int) config('mobile.delai_version_minimale_jours', 3);

        try {
            return Annonce::where('type', 'mise_a_jour')->where('statut', 'envoyee')->whereNotNull('version')
                ->where('derniere_diffusion', '<=', now()->subDays($jours))
                ->pluck('version')
                ->contains(fn (string $v) => version_compare($v, $version, '>='));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Plus haute version déjà annoncée ou programmée (pour ne jamais annoncer deux fois). */
    public static function derniereAnnoncee(): ?string
    {
        return self::plusHaute(Annonce::where('type', 'mise_a_jour')->whereNotNull('version')->pluck('version')->all());
    }

    /** @param  list<string>  $versions */
    public static function plusHaute(array $versions): ?string
    {
        return array_reduce(
            array_filter($versions, fn ($v) => is_string($v) && trim($v) !== ''),
            fn (?string $max, string $v) => $max === null || version_compare($v, $max, '>') ? $v : $max,
        );
    }
}
