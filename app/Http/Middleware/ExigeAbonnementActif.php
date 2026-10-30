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
            // Ce que les clients doivent s'encaisse toujours : l'argent est déjà dû.
            if ($sauf === 'dettes') {
                return $next($request);
            }

            $fin = $abonnement?->estEssai() ? 'Votre essai gratuit est terminé' : 'Votre abonnement a expiré';

            // Trois jours de grâce : la caisse vend encore le catalogue. Sans fin,
            // un stock saisi large, un restaurant ou un pressing (sans stock)
            // vendaient gratuitement pour toujours.
            if ($sauf === 'caisse' && $abonnement?->estEnGrace()) {
                if (! $this->aDesLignesLibres($request, $boutique)) {
                    return $next($request);
                }

                return response()->json([
                    'message' => $fin.' : jusqu’au '.$abonnement->finDeGrace()->format('d/m').', la caisse vend encore les articles du catalogue, mais plus hors catalogue. Abonnez-vous pour continuer.',
                    'code' => 'ABONNEMENT_EXPIRE',
                ], 403);
            }

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

    /**
     * Une ligne tapée à la main (nom et prix, sans article). Pas une liste
     * d'identifiants (l'addition d'un restaurant payée en partie), ni les frais
     * d'enlèvement d'une facture « distributeur » : ils suivent les articles.
     */
    private function aDesLignesLibres(Request $request, ?Boutique $boutique): bool
    {
        $lignes = $request->input('lignes');
        $distributeur = $boutique?->modele_facture === 'distributeur';

        return is_array($lignes) && collect($lignes)->contains(
            fn ($ligne): bool => is_array($ligne) && empty($ligne['produit_id']) && ! ($distributeur && ($ligne['frais'] ?? false))
        );
    }
}
