<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Annonce;
use App\Support\VersionApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appelé par la CI (tâche planifiée GitHub) quand une version est en
 * production sur le Play Store : l'annonce aux commerçants est programmée,
 * sans rien toucher à la main.
 *
 * Un délai de sécurité (MOBILE_DELAI_ANNONCE_HEURES) laisse au Play Store le
 * temps de diffuser la version à tous les téléphones : l'annonce part ensuite
 * par la file des annonces programmées (ecaisse:diffuser-annonces). Rappelé
 * autant de fois qu'on veut, il n'annonce jamais deux fois la même version.
 */
class PublicationPlayController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $jeton = (string) config('mobile.jeton_publication');
        abort_if($jeton === '' || ! hash_equals($jeton, (string) $request->bearerToken()), 403);

        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'nouveautes' => ['nullable', 'string', 'max:500'],
        ]);
        $version = $data['version'];

        $derniere = VersionApplication::derniereAnnoncee();
        if ($derniere !== null && version_compare($version, $derniere, '<=')) {
            return response()->json(['statut' => 'deja_annoncee', 'version' => $version, 'derniere' => $derniere]);
        }

        $nouveautes = trim((string) ($data['nouveautes'] ?? '')) ?: trim((string) config('mobile.nouveautes'));
        $annonce = Annonce::create([
            'type' => 'mise_a_jour',
            'titre' => 'Nouvelle version de l’application',
            'message' => $nouveautes !== '' ? $nouveautes : 'Mettez à jour l’application depuis le Play Store pour profiter des nouveautés.',
            'version' => $version,
            'lien' => (string) config('mobile.store_url'),
            'audience' => 'tous',
            'par_email' => (bool) config('mobile.annonce_par_email'),
            'statut' => 'programmee',
            'programmee_le' => now()->addHours((int) config('mobile.delai_annonce_heures')),
        ]);

        return response()->json(['statut' => 'programmee', 'version' => $version, 'le' => $annonce->programmee_le->toIso8601String()], 202);
    }
}
