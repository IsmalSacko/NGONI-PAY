<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Annonce;

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
