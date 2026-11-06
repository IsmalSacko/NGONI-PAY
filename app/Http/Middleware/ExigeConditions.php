<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ConditionsUtilisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sans acceptation de la version en vigueur des conditions, le Service ne
 * s'utilise pas : l'API refuse (403, code CONDITIONS_A_ACCEPTER) et le
 * back-office web renvoie vers la page d'acceptation.
 *
 * Restent ouverts : lire son compte, accepter, se déconnecter. Une
 * application d'avant 4.9.0 ne sait pas montrer les conditions : elle est
 * refusée aussi (MISE_A_JOUR_REQUISE), avec le lien du Play Store.
 */
class ExigeConditions
{
    /** Chemins (sans préfixe) toujours ouverts. */
    private const OUVERTS = ['api/moi', 'api/conditions/accepter', 'api/deconnexion', 'api/appareils', 'conditions/accepter', 'deconnexion'];

    public function __construct(private readonly ConditionsUtilisation $conditions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! config('conditions.exiger') || $user === null || $this->conditions->aAccepte($user) || in_array(trim($request->path(), '/'), self::OUVERTS, true)) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            // Une application d'avant 4.9.0 (ou sans version) ne sait pas montrer
            // les conditions : refusée aussi, avec de quoi se mettre à jour.
            // Sécurité : personne n'utilise le Service sans les avoir acceptées.
            if (! ConditionsUtilisation::clientSaitAccepter($request)) {
                return response()->json([
                    'message' => 'Mettez à jour Ngoni Caisse (Play Store), puis acceptez les conditions d’utilisation pour continuer.',
                    'code' => 'MISE_A_JOUR_REQUISE',
                    'store_url' => config('mobile.store_url'),
                ], 403);
            }

            return response()->json([
                'message' => 'Acceptez les nouvelles conditions d’utilisation pour continuer.',
                'code' => 'CONDITIONS_A_ACCEPTER',
            ], 403);
        }

        return redirect()->guest(route('conditions.accepter'));
    }
}
