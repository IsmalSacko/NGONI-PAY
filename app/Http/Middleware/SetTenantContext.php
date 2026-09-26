<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\BoutiqueActive;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Établit le contexte tenant après authentification.
 *
 * API : la boutique demandée par l'en-tête `X-Boutique` (refusée si le compte
 * n'y a pas de rôle). Back-office : celle gardée en session. À défaut, la
 * boutique par défaut du compte. Voir {@see BoutiqueActive}.
 */
class SetTenantContext
{
    public function __construct(private readonly BoutiqueActive $boutiqueActive) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $enTete = $request->header(BoutiqueActive::EN_TETE);

            $this->boutiqueActive->poser(
                $user,
                $enTete ?? ($request->hasSession() ? $request->session()->get(BoutiqueActive::CLE_SESSION) : null),
                strict: $enTete !== null,
            );
        }

        return $next($request);
    }
}
