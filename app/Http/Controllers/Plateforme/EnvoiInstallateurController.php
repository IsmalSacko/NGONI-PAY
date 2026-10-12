<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Support\InstallateursBureau;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Dépôt d'un installateur Windows ou macOS depuis la console, par morceaux.
 *
 * Le serveur refuse les envois de plus de 2 Mo (PHP) : la page envoie le
 * fichier en morceaux de 1,5 Mo, dans l'ordre, et suit la progression. Les
 * morceaux s'ajoutent bout à bout dans telechargements/envois/ ; au dernier,
 * le fichier est contrôlé (signature Windows « MZ », image disque Apple
 * « koly ») puis mis en service ({@see InstallateursBureau::mettreEnService()}).
 * Un morceau perdu se renvoie : la réponse donne l'index attendu.
 */
class EnvoiInstallateurController extends Controller
{
    /** Taille maximale d'un installateur, en morceaux de 1,5 Mo (≈ 450 Mo). */
    private const MORCEAUX_MAX = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'systeme' => ['required', 'in:'.implode(',', array_keys((array) config('bureau.systemes')))],
            'version' => ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/'],
            'envoi' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:'.self::MORCEAUX_MAX],
            'morceau' => ['required', 'file', 'max:2048'],
        ], ['version.regex' => 'La version s’écrit comme 1.0.1.']);

        $disque = Storage::disk('local');
        $partiel = config('bureau.dossier')."/envois/{$data['envoi']}.part";
        $suivi = config('bureau.dossier')."/envois/{$data['envoi']}.json";
        $attendu = $disque->exists($suivi) ? (int) (json_decode((string) $disque->get($suivi), true)['suivant'] ?? 0) : 0;

        if ((int) $data['index'] !== $attendu) {
            return response()->json(['message' => 'Morceau hors d’ordre.', 'attendu' => $attendu], 409);
        }

        $octets = (string) file_get_contents($data['morceau']->getRealPath());
        if ($attendu === 0) {
            // Les restes d'envois abandonnés (plus d'un jour) partent au début du suivant.
            foreach ($disque->files(config('bureau.dossier').'/envois') as $reste) {
                if ($disque->lastModified($reste) < now()->subDay()->getTimestamp()) {
                    $disque->delete($reste);
                }
            }
            if ($data['systeme'] === 'windows' && ! str_starts_with($octets, 'MZ')) {
                throw ValidationException::withMessages(['morceau' => ['Ce fichier n’est pas un installateur Windows (.exe).']]);
            }
            $disque->put($partiel, $octets);
        } else {
            // Ajout direct en fin de fichier : append() du disque relirait tout le fichier à chaque morceau.
            file_put_contents($disque->path($partiel), $octets, FILE_APPEND | LOCK_EX);
        }
        $disque->put($suivi, (string) json_encode(['suivant' => $attendu + 1]));

        if ($attendu + 1 < (int) $data['total']) {
            return response()->json(['recu' => $attendu + 1]);
        }

        // Dernier morceau : contrôle du fichier entier, puis mise en service.
        $disque->delete($suivi);
        if ($data['systeme'] === 'mac' && ! $this->estImageDisque($disque->path($partiel))) {
            $disque->delete($partiel);
            throw ValidationException::withMessages(['morceau' => ['Ce fichier n’est pas une image disque macOS (.dmg).']]);
        }
        InstallateursBureau::mettreEnService($data['systeme'], $data['version'], $partiel, $request->user());

        return response()->json([
            'termine' => true,
            'message' => config("bureau.systemes.{$data['systeme']}.nom")." {$data['version']} est en ligne sur la vitrine.",
        ]);
    }

    /** Une image disque Apple se termine par un bloc de 512 octets qui commence par « koly ». */
    private function estImageDisque(string $chemin): bool
    {
        $taille = (int) filesize($chemin);
        if ($taille < 512) {
            return false;
        }
        $f = fopen($chemin, 'rb');
        fseek($f, $taille - 512);
        $signature = fread($f, 4);
        fclose($f);

        return $signature === 'koly';
    }
}
