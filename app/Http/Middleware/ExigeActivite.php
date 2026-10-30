<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Boutique;
use App\Models\Tutoriel;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `activite:<code>` : la route n'ouvre que pour une boutique de cette
 * activité (le transfert d'argent pour une cabine). Posé après `tenant`.
 */
class ExigeActivite
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $activite): Response
    {
        if (Boutique::find($this->tenant->boutiqueId())?->activite === $activite) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Cette fonction demande l’activité « '.(Tutoriel::CATEGORIES[$activite] ?? $activite).' » (Ma boutique → Activité).',
            'code' => 'ACTIVITE_REQUISE',
        ], 403);
    }
}
