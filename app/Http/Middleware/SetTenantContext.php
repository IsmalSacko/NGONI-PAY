<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Établit le contexte tenant après authentification Sanctum.
 *
 * Lit la boutique de l'utilisateur authentifié, l'injecte dans le
 * {@see TenantContext} (utilisé par le Global Scope) et dans Spatie
 * (équipe = boutique) pour l'isolation des rôles/permissions entre
 * boutiques.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->boutique_id !== null) {
            $this->tenant->setBoutique($user->boutique_id);

            if (App::bound(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)
                    ->setPermissionsTeamId($user->boutique_id);
            }
        }

        return $next($request);
    }
}
