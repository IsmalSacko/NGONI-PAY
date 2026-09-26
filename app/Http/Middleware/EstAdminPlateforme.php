<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Console de l'exploitant : réservée aux comptes `est_admin_plateforme`. */
class EstAdminPlateforme
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->est_admin_plateforme === true, 403);

        return $next($request);
    }
}
