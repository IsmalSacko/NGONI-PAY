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
 * consultable mais plus rien ne se modifie : catalogue, stocks, clients,
 * équipe, achats, séances de caisse… Posé sur les routes d'écriture, après
 * `tenant`.
 *
 * La caisse (`abonnement:caisse`) ne vend plus non plus, dès la fin (16 h le
 * dernier jour), sans délai de grâce. Seuls les remboursements de dettes
 * (`abonnement:dettes`) passent toujours : l'argent est déjà dû.
 */
class ExigeAbonnementActif
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AbonnementService $abonnements,
    ) {}

    public function handle(Request $request, Closure $next, ?string $sauf = null): Response
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
            // Ce que les clients doivent s'encaisse toujours : l'argent est déjà dû.
            if ($sauf === 'dettes') {
                return $next($request);
            }

            $fin = $abonnement?->estEssai() ? 'Votre essai gratuit est terminé' : 'Votre abonnement a expiré';

            if ($sauf === 'caisse') {
                return response()->json([
                    'message' => $fin.' : la caisse ne vend plus. Vos ventes, vos clients et votre stock restent consultables. Abonnez-vous pour continuer.',
                    'code' => 'ABONNEMENT_EXPIRE',
                ], 403);
            }

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
