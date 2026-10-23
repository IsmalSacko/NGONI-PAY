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
        $boutique = Boutique::find($this->tenant->boutiqueId());
        if ($this->abonnements->permet($boutique, $fonctionnalite)) {
            return $next($request);
        }
        // Après l'échéance, les achats se consultent encore si l'offre les
        // comprenait : l'état d'abonnement les annonce ouverts à l'application.
        // Les refuser ici la faisait tourner sur un sablier (« Achats »,
        // « Commander ») : elle redemandait sans fin ce qu'on lui refusait.
        if ($request->isMethodSafe() && in_array($fonctionnalite, Plan::CONSULTABLES_APRES_ECHEANCE, true)
            && $this->abonnements->planInclut($boutique, $fonctionnalite)) {
            return $next($request);
        }

        // Essai ou abonnement échu : la fonction n'est pas hors de l'offre, c'est
        // l'offre qui est terminée — le dire, plutôt que « passez à une offre supérieure ».
        $termine = ! ($this->abonnements->pourBoutique($boutique)?->estEnCours() ?? false);
        $message = (Plan::FONCTIONNALITES[$fonctionnalite] ?? 'Cette fonction').($termine
            ? ' : votre essai ou abonnement est terminé, cette fonction n’est plus disponible.'
            : ' : non incluse dans votre offre. Passez à une offre supérieure.');

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => $fonctionnalite], 403)
            : abort(403, $message);
    }
}
