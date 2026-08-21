<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe l'accès d'un compte désactivé, jeton en cours ou non.
 *
 * La désactivation révoque les jetons du compte, mais elle peut aussi être
 * décidée ailleurs — un script, une correction en base. Sans ce filtre, un jeton
 * déjà émis continuerait d'encaisser jusqu'à sa prochaine expiration.
 *
 * Appliqué à toutes les requêtes d'API : il ne fait rien tant qu'aucun compte
 * n'est authentifié.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            // Le jeton est retiré au passage : le refus ne se répète pas à chaque
            // requête d'une application qui l'ignorerait.
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Votre compte a été désactivé. Contactez le service '
                    . 'client pour le réactiver.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], 403);
        }

        return $next($request);
    }
}
