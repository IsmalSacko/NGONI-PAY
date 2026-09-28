<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Presence\Appareil;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Note la dernière activité de l'utilisateur connecté, pour la console :
 * « En ligne », « il y a 12 min », l'appareil, la boutique.
 *
 * Lu après la requête : l'authentification et la boutique active sont alors
 * connues. Une écriture par minute et par utilisateur au plus — l'application
 * interroge le serveur chaque minute, écrire à chaque requête ne dirait rien
 * de plus. L'écriture ne touche pas updated_at : être vu n'est pas modifier.
 */
class NoterPresence
{
    /** En deçà, la présence déjà notée suffit. */
    public const INTERVALLE_SECONDES = 60;

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user instanceof User && ! $this->dejaNote($user)) {
            $appareil = Appareil::depuisRequete($request);
            DB::table('users')->where('id', $user->id)->update([
                'vu_le' => now(),
                // Un appareil inconnu (vieille application) n'efface pas ce qu'on savait déjà.
                'vu_plateforme' => $appareil->plateforme ?? $user->vu_plateforme,
                'vu_modele' => $appareil->modele ?? $user->vu_modele,
                'vu_version' => $appareil->version ?? $user->vu_version,
                'vu_boutique_id' => $this->tenant->boutiqueId() ?? $user->vu_boutique_id ?? $user->boutique_id,
            ]);
        }

        return $response;
    }

    private function dejaNote(User $user): bool
    {
        return $user->vu_le !== null && $user->vu_le->gt(now()->subSeconds(self::INTERVALLE_SECONDES));
    }
}
