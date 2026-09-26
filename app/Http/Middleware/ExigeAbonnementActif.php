<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Boutique;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sans abonnement en cours (essai terminé, plan expiré ou révoqué), tout reste
 * consultable mais plus rien ne se fait : encaisser, modifier le catalogue, les
 * stocks, les clients, ouvrir une séance de caisse… Posé sur les routes
 * d'écriture, après `tenant`.
 */
class ExigeAbonnementActif
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AbonnementService $abonnements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $boutique = Boutique::find($this->tenant->boutiqueId());
        $abonnement = $this->abonnements->pourBoutique($boutique);

        // Rejeu d'une vente déjà enregistrée (réponse perdue, file hors ligne) :
        // le serveur la renvoie telle quelle, il ne crée rien.
        $reference = $request->input('reference_locale');
        if (is_string($reference) && $reference !== '' && $request->routeIs('ventes.store')
            && Vente::where('reference_locale', $reference)->exists()) {
            return $next($request);
        }

        if ($abonnement === null || ! $abonnement->estEnCours()) {
            return response()->json([
                'message' => $abonnement?->estEssai()
                    ? 'Votre essai gratuit est terminé. Abonnez-vous pour continuer.'
                    : 'Votre abonnement a expiré. Renouvelez-le pour continuer.',
                'code' => 'ABONNEMENT_EXPIRE',
            ], 403);
        }

        return $next($request);
    }
}
