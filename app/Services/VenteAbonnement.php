<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Un abonnement payé — en ligne (Jèko, FedaPay, pawaPay) ou validé à la main
 * dans la console — devient une vente dans la boutique de l'exploitant
 * (config `ecaisse.boutique_ventes_abonnements`), comme s'il l'avait encaissé
 * à sa caisse : même numéro de ticket, même séance, le commerçant en client
 * avec son téléphone, son e-mail et sa boutique. Jusqu'ici, l'exploitant la
 * retrouvait et la saisissait à la main. Un abonnement accordé sans paiement
 * (« accorder », « prolonger ») n'a pas de montant : pas de vente.
 *
 * Après la validation de l'abonnement et hors de sa transaction : une vente
 * qui échoue ne doit jamais défaire l'abonnement payé — l'exploitant est
 * prévenu et la saisit lui-même. La référence tirée de la demande rend
 * l'enregistrement idempotent : un appel du prestataire rejoué ne double pas
 * la vente.
 */
class VenteAbonnement
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly VenteService $ventes,
    ) {}

    /** À appeler dans la transaction qui valide l'abonnement : s'exécute après elle. */
    public function apresValidation(DemandeAbonnement $demande): void
    {
        DB::afterCommit(function () use ($demande): void {
            try {
                $this->enregistrer($demande->fresh());
            } catch (Throwable $e) {
                report($e);
                app(AlertesExploitant::class)->envoyer(
                    'Vente d’abonnement non enregistrée',
                    ($demande->boutique?->nom ?? 'Une boutique').' : l’abonnement est actif, mais sa vente n’a pas pu être enregistrée. À saisir à la main.',
                    '/console/demandes',
                );
            }
        });
    }

    public function enregistrer(DemandeAbonnement $demande): ?Vente
    {
        $boutiqueId = config('ecaisse.boutique_ventes_abonnements');
        if (blank($boutiqueId) || (int) $demande->montant <= 0) {
            return null;
        }
        $boutique = Boutique::find($boutiqueId);
        if ($boutique === null) {
            Log::warning('Boutique des ventes d’abonnement introuvable', ['boutique_id' => $boutiqueId]);

            return null;
        }

        // La caisse de l'exploitant : son compte rattaché à cette boutique,
        // sinon le propriétaire de la boutique.
        $caissier = User::where('est_admin_plateforme', true)->where('boutique_id', $boutique->id)->first()
            ?? User::find($boutique->proprietaire_id);
        if ($caissier === null) {
            Log::warning('Aucun compte pour encaisser les ventes d’abonnement', ['boutique_id' => $boutique->id]);

            return null;
        }

        $precedente = $this->tenant->boutiqueId();
        $this->tenant->setBoutique($boutique->id);
        try {
            return $this->ventes->encaisser([
                'reference_locale' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'ngoni-caisse/demande-abonnement/'.$demande->id)->toString(),
                'client_id' => $this->client($demande)?->id,
                'lignes' => $this->lignes($demande),
                'remise' => 0,
                'moyen_paiement' => $this->moyen($demande)->value,
                'montant_recu' => null,
                'vendue_hors_ligne' => false,
            ], $caissier);
        } finally {
            $this->tenant->setBoutique($precedente);
        }
    }

    /**
     * Le commerçant, client de la boutique de l'exploitant : retrouvé par son
     * téléphone (une fiche saisie à la main est gardée telle quelle), sinon
     * créé avec son nom, son téléphone, son e-mail et sa boutique.
     */
    private function client(DemandeAbonnement $demande): ?Client
    {
        $commercant = User::find($demande->user_id);
        if ($commercant === null) {
            return null;
        }
        $telephone = $commercant->phone ?: $demande->telephone_contact;
        $existant = filled($telephone) ? Client::where('telephone', $telephone)->first() : null;
        if ($existant !== null) {
            if (blank($existant->email) && filled($commercant->email)) {
                $existant->update(['email' => $commercant->email]);
            }

            return $existant;
        }

        $boutique = $demande->boutique;

        return Client::create([
            'nom' => $commercant->name,
            'telephone' => $telephone,
            'email' => $commercant->email,
            'adresse' => $boutique === null ? null : trim('Boutique '.$boutique->nom.($boutique->pays ? ' · '.$boutique->pays : '')),
        ]);
    }

    /**
     * « Plan Pro · 1 mois », au prix de l'abonnement. Les frais du paiement
     * mobile vont au prestataire, pas à l'exploitant : ils ne sont pas sur sa
     * facture.
     *
     * @return list<array{libelle: string, prix_unitaire: int, quantite: int}>
     */
    private function lignes(DemandeAbonnement $demande): array
    {
        $plan = Plan::parCode($demande->plan)?->nom ?? ucfirst((string) $demande->plan);
        $duree = $demande->mois ? $demande->mois.' mois' : $demande->cycle?->libelle();
        $moyen = $demande->libellePaiementEnLigne();
        $libelle = 'Plan '.$plan.($duree ? ' · '.$duree : '').($moyen ? ' — '.$moyen : '');

        return [['libelle' => mb_substr($libelle, 0, 120), 'prix_unitaire' => (int) $demande->montant, 'quantite' => 1]];
    }

    /** Le moyen de la caisse le plus proche ; les autres (MTN, Free…) sont nommés sur la ligne. */
    private function moyen(DemandeAbonnement $demande): MoyenPaiement
    {
        $code = mb_strtolower((string) $demande->moyen);

        return match (true) {
            str_contains($code, 'wave') => MoyenPaiement::Wave,
            str_contains($code, 'orange') => MoyenPaiement::OrangeMoney,
            str_contains($code, 'moov') => MoyenPaiement::MoovMoney,
            str_contains($code, 'carte') => MoyenPaiement::Carte,
            default => MoyenPaiement::Virement,
        };
    }
}
