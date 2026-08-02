<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware dédié au panneau web admin, indépendant de CheckRole (utilisé par l'API)
 * pour ne partager aucun fichier avec app/Http/Controllers/Api/**.
 */
class EnsureIsSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== User::ROLE_SYSTEM_ADMIN) {
            abort(403, "Accès réservé à l'administration.");
        }

        return $next($request);
    }
}
