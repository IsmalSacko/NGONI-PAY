<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Installateurs Windows et macOS déposés sur le serveur ({@see config/bureau.php}).
 */
final class InstallateursBureau
{
    /** Chemin du fichier d'un système (dans le disque « local »), ou null s'il n'est pas connu. */
    public static function chemin(string $systeme): ?string
    {
        $config = config("bureau.systemes.{$systeme}");
        if (! is_array($config)) {
            return null;
        }

        return config('bureau.dossier').'/'.str_replace('{version}', (string) config('bureau.version'), $config['fichier']);
    }

    /**
     * Les installateurs présents, pour la vitrine : système, nom affiché,
     * configuration requise, taille en Mo.
     *
     * @return list<array{systeme: string, nom: string, requis: string, mo: int}>
     */
    public static function disponibles(): array
    {
        $liste = [];
        foreach (array_keys((array) config('bureau.systemes')) as $systeme) {
            $chemin = self::chemin($systeme);
            if ($chemin === null || ! Storage::disk('local')->exists($chemin)) {
                continue;
            }
            $liste[] = [
                'systeme' => $systeme,
                'nom' => (string) config("bureau.systemes.{$systeme}.nom"),
                'requis' => (string) config("bureau.systemes.{$systeme}.requis"),
                'mo' => (int) max(1, round(Storage::disk('local')->size($chemin) / 1048576)),
            ];
        }

        return $liste;
    }
}
