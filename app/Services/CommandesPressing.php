<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandePressing;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\ServicePressing;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commandes d'un pressing : dépôt (numéro unique, prix figés, acompte),
 * linge prêt, retrait (la vente naît là, acompte compris) et annulation.
 * Réservé aux boutiques en activité pressing : les autres n'y ont pas accès.
 */
class CommandesPressing
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function boutique(): Boutique
    {
        $boutique = Boutique::find($this->tenant->boutiqueId());
        if ($boutique === null || ! $boutique->estPressing()) {
            throw ValidationException::withMessages(['boutique' => ['Les commandes sont réservées aux pressings.']]);
        }

        return $boutique;
    }

    /**
     * @param  array{client_id: string, lignes: list<array{produit_id: string, service_id: string, quantite: int, defauts?: ?string}>, express?: bool, acompte?: int, moyen_acompte?: ?string, retrait_prevu_le?: ?string, notes?: ?string, reference_locale?: ?string}  $data
     */
    public function deposer(array $data, User $agent): CommandePressing
    {
        $boutique = $this->boutique();

        if (! empty($data['reference_locale'])) {
            $existante = CommandePressing::where('reference_locale', $data['reference_locale'])->first();
            if ($existante !== null) {
                return $existante->load('lignes', 'client');
            }
        }

        $express = (bool) ($data['express'] ?? false);
        if ($express && ! app(AbonnementService::class)->permet($boutique, Plan::PRESSING_AVANCE)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Le service express fait partie de l’offre Pro.', 'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => Plan::PRESSING_AVANCE,
            ], 403));
        }

        if (! Client::whereKey($data['client_id'])->exists()) {
            throw ValidationException::withMessages(['client_id' => ['Choisissez le client qui dépose.']]);
        }

        // Les prix du jour, figés dans la commande : un tarif qui change ensuite ne la touche pas.
        $produits = Produit::whereIn('id', array_column($data['lignes'], 'produit_id'))->get()->keyBy('id');
        $services = ServicePressing::whereIn('id', array_column($data['lignes'], 'service_id'))->get()->keyBy('id');
        $lignes = [];
        $total = 0;
        foreach ($data['lignes'] as $ligne) {
            $produit = $produits->get($ligne['produit_id']);
            $service = $services->get($ligne['service_id']);
            $prix = $produit === null || $service === null ? null : $produit->prixService($service->id, $express);
            if ($prix === null) {
                throw ValidationException::withMessages(['lignes' => ['« '.($produit?->nom ?? 'Cet habit').' » n’a pas de prix pour cette prestation.']]);
            }
            $quantite = (int) $ligne['quantite'];
            $lignes[] = [
                'produit_id' => $produit->id, 'service_id' => $service->id, 'nom' => $produit->nom, 'service' => $service->nom,
                'quantite' => $quantite, 'prix_unitaire' => $prix, 'total_ligne' => $prix * $quantite,
                'defauts' => filled($ligne['defauts'] ?? null) ? trim((string) $ligne['defauts']) : null,
            ];
            $total += $prix * $quantite;
        }

        $acompte = (int) ($data['acompte'] ?? 0);
        if ($acompte > $total) {
            throw ValidationException::withMessages(['acompte' => ['L’acompte dépasse le total de la commande.']]);
        }

        return DB::transaction(function () use ($boutique, $data, $agent, $express, $lignes, $total, $acompte): CommandePressing {
            // Un numéro par commande, jamais deux fois le même, même à plusieurs agents.
            $boutique = Boutique::whereKey($boutique->id)->lockForUpdate()->first();
            $numero = max((int) $boutique->dernier_numero_commande, (int) CommandePressing::withoutBoutiqueScope()->where('boutique_id', $boutique->id)->max('numero')) + 1;
            $boutique->forceFill(['dernier_numero_commande' => $numero])->save();

            $commande = CommandePressing::create([
                'boutique_id' => $boutique->id,
                'numero' => $numero,
                'reference_locale' => $data['reference_locale'] ?? null,
                'client_id' => $data['client_id'],
                'user_id' => $agent->id,
                'statut' => CommandePressing::DEPOSEE,
                'express' => $express,
                'total' => $total,
                'acompte' => $acompte,
                'moyen_acompte' => $acompte > 0 ? ($data['moyen_acompte'] ?? 'especes') : null,
                // Par défaut : dans 2 jours à 20 h, le lendemain en express.
                'retrait_prevu_le' => filled($data['retrait_prevu_le'] ?? null)
                    ? $data['retrait_prevu_le'] : now()->addDays($express ? 1 : 2)->setTime(20, 0),
                'notes' => $data['notes'] ?? null,
            ]);
            $commande->lignes()->createMany($lignes);

            return $commande->load('lignes', 'client');
        });
    }

    public function marquerPrete(CommandePressing $commande): CommandePressing
    {
        $this->exigerOuverte($commande);
        $commande->update(['statut' => CommandePressing::PRETE, 'prete_le' => now()]);

        return $commande;
    }

    /**
     * Retrait : la vente naît, aux prix figés du dépôt, acompte compris ; la
     * commande est clôturée. [montant_donne] : ce que le client remet maintenant.
     *
     * @param  array{moyen_paiement: string, montant_donne?: ?int}  $data
     */
    public function retirer(CommandePressing $commande, array $data, User $caissier): CommandePressing
    {
        $this->exigerOuverte($commande);
        $commande->loadMissing('lignes');

        $donne = $data['montant_donne'] ?? null;
        $vente = app(VenteService::class)->encaisser([
            'client_id' => $commande->client_id,
            'express' => $commande->express,
            'lignes' => $commande->lignes->map(fn ($l) => $l->produit_id !== null
                ? ['produit_id' => $l->produit_id, 'service_id' => $l->service_id, 'quantite' => $l->quantite, 'prix_fige' => $l->prix_unitaire]
                // Habit supprimé depuis : la ligne garde son nom et son prix.
                : ['libelle' => $l->nom.' · '.$l->service, 'prix_unitaire' => $l->prix_unitaire, 'quantite' => $l->quantite, 'taux_tva' => 0])->all(),
            'moyen_paiement' => $data['moyen_paiement'],
            // L'acompte a déjà été versé : le reçu compte l'acompte et ce qui est remis maintenant.
            'montant_recu' => $donne === null ? $commande->total : $commande->acompte + (int) $donne,
        ], $caissier);

        $commande->update([
            'statut' => CommandePressing::RETIREE, 'retiree_le' => now(), 'retiree_par' => $caissier->id, 'vente_id' => $vente->id,
        ]);

        return $commande;
    }

    public function annuler(CommandePressing $commande, ?string $motif): CommandePressing
    {
        $this->exigerOuverte($commande);
        $commande->update(['statut' => CommandePressing::ANNULEE, 'annulee_le' => now(), 'motif_annulation' => $motif]);

        return $commande;
    }

    private function exigerOuverte(CommandePressing $commande): void
    {
        if (! in_array($commande->statut, [CommandePressing::DEPOSEE, CommandePressing::PRETE], true)) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà '.($commande->statut === CommandePressing::RETIREE ? 'retirée' : 'annulée').'.']]);
        }
    }
}
