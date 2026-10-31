<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Installateurs Windows et macOS déposés sur le serveur ({@see config/bureau.php}).
 *
 * Le dernier dépôt de chaque système est décrit dans un manifeste
 * (telechargements/bureau.json, disque « local ») : version, fichier, taille,
 * date et auteur. Sans manifeste, la version de la configuration fait foi.
 */
final class InstallateursBureau
{
    public const MANIFESTE = 'telechargements/bureau.json';

    /** @return array<string, array{version: string, fichier: string, octets?: int, depose_le?: string, par?: string}> */
    public static function manifeste(): array
    {
        $disque = Storage::disk('local');
        $contenu = $disque->exists(self::MANIFESTE) ? json_decode((string) $disque->get(self::MANIFESTE), true) : null;

        return is_array($contenu) ? $contenu : [];
    }

    public static function connu(string $systeme): bool
    {
        return is_array(config("bureau.systemes.{$systeme}"));
    }

    /** Nom du fichier d'une version : « NgoniCaisse-1.0.1-macOS.dmg ». */
    public static function nomFichier(string $systeme, string $version): string
    {
        return str_replace('{version}', $version, (string) config("bureau.systemes.{$systeme}.fichier"));
    }

    public static function version(string $systeme): string
    {
        return (string) (self::manifeste()[$systeme]['version'] ?? config('bureau.version'));
    }

    /** Chemin du fichier en service (disque « local »), ou null pour un système inconnu. */
    public static function chemin(string $systeme): ?string
    {
        if (! self::connu($systeme)) {
            return null;
        }
        $fichier = self::manifeste()[$systeme]['fichier'] ?? self::nomFichier($systeme, self::version($systeme));

        return config('bureau.dossier').'/'.$fichier;
    }

    /**
     * Les installateurs présents : système, nom affiché, version, configuration
     * requise, taille en Mo, et pour la console la date et l'auteur du dépôt.
     *
     * @return list<array{systeme: string, nom: string, version: string, requis: string, mo: int, depose_le: ?string, par: ?string}>
     */
    public static function disponibles(): array
    {
        $manifeste = self::manifeste();
        $liste = [];
        foreach (array_keys((array) config('bureau.systemes')) as $systeme) {
            // Sur la boutique du système : le bouton y mène, fichier ou non.
            if (filled($store = config("bureau.systemes.{$systeme}.store"))) {
                $liste[] = [
                    'systeme' => $systeme,
                    'nom' => (string) config("bureau.systemes.{$systeme}.nom"),
                    'version' => null,
                    'requis' => (string) config("bureau.systemes.{$systeme}.requis"),
                    'mo' => null,
                    'store' => (string) $store,
                    'depose_le' => null,
                    'par' => null,
                ];

                continue;
            }
            $chemin = self::chemin($systeme);
            if ($chemin === null || ! Storage::disk('local')->exists($chemin)) {
                continue;
            }
            $liste[] = [
                'systeme' => $systeme,
                'nom' => (string) config("bureau.systemes.{$systeme}.nom"),
                'version' => self::version($systeme),
                'requis' => (string) config("bureau.systemes.{$systeme}.requis"),
                'mo' => (int) max(1, round(Storage::disk('local')->size($chemin) / 1048576)),
                'store' => null,
                'depose_le' => $manifeste[$systeme]['depose_le'] ?? null,
                'par' => $manifeste[$systeme]['par'] ?? null,
            ];
        }

        return $liste;
    }

    /**
     * Met en service un installateur reçu (chemin sur le disque « local ») :
     * renommé selon sa version, l'ancien fichier du même système retiré, le
     * manifeste mis à jour. Les boutons de la vitrine suivent aussitôt.
     */
    public static function mettreEnService(string $systeme, string $version, string $recu, User $par): string
    {
        if (! self::connu($systeme)) {
            throw new RuntimeException("Système inconnu : {$systeme}");
        }
        $disque = Storage::disk('local');
        $ancien = $disque->exists((string) self::chemin($systeme)) ? self::chemin($systeme) : null;
        $nouveau = config('bureau.dossier').'/'.self::nomFichier($systeme, $version);

        $disque->delete($nouveau);
        $disque->move($recu, $nouveau);
        if ($ancien !== null && $ancien !== $nouveau) {
            $disque->delete($ancien);
        }

        $manifeste = self::manifeste();
        $manifeste[$systeme] = [
            'version' => $version,
            'fichier' => basename($nouveau),
            'octets' => (int) $disque->size($nouveau),
            'depose_le' => now()->toIso8601String(),
            'par' => (string) $par->name,
        ];
        $disque->put(self::MANIFESTE, (string) json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $nouveau;
    }
}
