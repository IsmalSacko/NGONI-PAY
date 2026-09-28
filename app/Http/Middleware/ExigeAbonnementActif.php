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
 * Sauf la caisse (`abonnement:caisse`) : encaisser les articles du catalogue et
 * les remboursements de dettes continue. Le stock, lui, ne se réapprovisionne
 * plus — c'est lui qui pousse à s'abonner, sans couper le commerçant de ses
 * clients. Les lignes libres (nom et prix tapés, sans stock) restent refusées :
 * elles permettraient de vendre indéfiniment sans catalogue.
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
            if ($sauf === 'caisse' && ! $this->aDesLignesLibres($request)) {
                return $next($request);
            }

            if ($sauf === 'caisse') {
                return response()->json([
                    'message' => ($abonnement?->estEssai() ? 'Votre essai gratuit est terminé' : 'Votre abonnement a expiré')
                        .' : la caisse vend encore les articles du catalogue, mais plus hors catalogue. Abonnez-vous pour continuer.',
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

    private function aDesLignesLibres(Request $request): bool
    {
        $lignes = $request->input('lignes');

        return is_array($lignes) && collect($lignes)->contains(
            fn ($ligne): bool => ! is_array($ligne) || empty($ligne['produit_id'])
        );
    }
}
