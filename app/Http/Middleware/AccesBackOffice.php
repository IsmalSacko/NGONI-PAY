<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Boutique;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Support\Tenancy\BoutiqueActive;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le back-office web est réservé aux admins et gérants de la boutique active.
 *
 * Caissier ici mais gérant ailleurs : on bascule sur une boutique qu'il gère.
 * Caissier partout : déconnecté, avec l'explication — il encaisse depuis
 * l'application.
 */
class AccesBackOffice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->can('backoffice.access')) {
            // Une offre sans back-office (Plan::BACKOFFICE_WEB, console) : on
            // reste sur l'application. L'exploitant garde sa console.
            $boutique = Boutique::find(app(TenantContext::class)->boutiqueId());
            if (! $user->est_admin_plateforme && ! app(AbonnementService::class)->planInclut($boutique, Plan::BACKOFFICE_WEB)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('connexion')->with('alerte', self::HORS_OFFRE);
            }

            return $next($request);
        }

        $gerees = $user->boutiquesBackOffice();
        if ($gerees !== []) {
            $request->session()->put(BoutiqueActive::CLE_SESSION, $gerees[0]);

            return redirect()->to($request->fullUrl());
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('connexion')->with('alerte', self::MESSAGE);
    }

    public const HORS_OFFRE = 'Le back-office web n’est pas inclus dans votre offre. Passez à une offre supérieure depuis l’application.';

    public const MESSAGE = 'Le back-office est réservé aux administrateurs et aux gérants. '
        .'Les caissiers encaissent depuis l’application Ngoni Caisse.';
}
