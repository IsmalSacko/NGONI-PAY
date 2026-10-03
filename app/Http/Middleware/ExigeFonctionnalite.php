<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Boutique;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `fonctionnalite:<code>` : la route n'ouvre que si le plan de la boutique
 * inclut cette fonction (Plan::FONCTIONNALITES, cochée dans la console).
 * Posé après `tenant`.
 */
class ExigeFonctionnalite
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AbonnementService $abonnements,
    ) {}

    public function handle(Request $request, Closure $next, string $fonctionnalite): Response
    {
        if ($this->abonnements->permet(Boutique::find($this->tenant->boutiqueId()), $fonctionnalite)) {
            return $next($request);
        }

        $message = (Plan::FONCTIONNALITES[$fonctionnalite] ?? 'Cette fonction').' : non incluse dans votre offre. Passez à une offre supérieure.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => $fonctionnalite], 403)
            : abort(403, $message);
    }
}
