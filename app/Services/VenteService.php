<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Enums\TypeMouvementStock;
use App\Models\Boutique;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Encaissement d'une vente à la caisse tactile.
 *
 * Les prix et taux de TVA ne sont JAMAIS pris depuis le client : ils sont
 * relus depuis le catalogue au moment de l'encaissement, pour qu'une
 * tablette compromise ou désynchronisée ne puisse pas imposer son propre
 * prix. Seules les quantités viennent du client.
 *
 * `reference_locale` (UUID généré par la tablette) rend l'appel idempotent :
 * une vente hors ligne rejouée au retour du réseau ne se double pas.
 */
class VenteService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{
     *     reference_locale: ?string,
     *     client_id: ?string,
     *     lignes: list<array{produit_id: string, quantite: int}>,
     *     remise: int,
     *     moyen_paiement: string,
     *     montant_recu: ?int,
     *     vendue_hors_ligne: bool,
     * }  $data
     */
    public function encaisser(array $data, User $caissier): Vente
    {
        $boutiqueId = $this->tenant->boutiqueId();

        if ($boutiqueId === null) {
            throw ValidationException::withMessages(['boutique' => ['Aucune boutique active.']]);
        }

        if (! empty($data['reference_locale'])) {
            $existante = Vente::where('reference_locale', $data['reference_locale'])->first();

            if ($existante !== null) {
                return $existante->load('lignes');
            }
        }

        return DB::transaction(function () use ($data, $caissier, $boutiqueId): Vente {
            // Verrouille la ligne de la boutique : sérialise l'attribution du
            // numéro de ticket entre caisses concurrentes de la même
            // boutique, sans bloquer les autres boutiques.
            Boutique::whereKey($boutiqueId)->lockForUpdate()->first();

            $produits = Produit::whereIn('id', array_column($data['lignes'], 'produit_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lignes = [];
            $sousTotal = 0;

            foreach ($data['lignes'] as $ligne) {
                $produit = $produits->get($ligne['produit_id']);

                if ($produit === null) {
                    throw ValidationException::withMessages(['lignes' => ["Produit introuvable : {$ligne['produit_id']}."]]);
                }

                if ($produit->stock < $ligne['quantite']) {
                    throw ValidationException::withMessages(['lignes' => ["Stock insuffisant pour « {$produit->nom} » (reste {$produit->stock})."]]);
                }

                $totalLigne = $produit->prix_vente * $ligne['quantite'];
                $sousTotal += $totalLigne;

                $lignes[] = [
                    'produit' => $produit,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $produit->prix_vente,
                    'taux_tva' => $produit->taux_tva,
                    'total_ligne' => $totalLigne,
                ];
            }

            $remise = min($data['remise'] ?? 0, $sousTotal);
            $total = $sousTotal - $remise;

            $moyenPaiement = MoyenPaiement::from($data['moyen_paiement']);
            $montantRecu = $moyenPaiement === MoyenPaiement::Especes ? ($data['montant_recu'] ?? $total) : null;

            if ($moyenPaiement === MoyenPaiement::Especes && $montantRecu < $total) {
                throw ValidationException::withMessages(['montant_recu' => ['Le montant reçu est inférieur au total.']]);
            }

            $tva = 0;
            foreach ($lignes as &$l) {
                $part = $sousTotal > 0 ? $remise * ($l['total_ligne'] / $sousTotal) : 0;
                $net = $l['total_ligne'] - $part;
                $tvaLigne = (int) round($net * (float) $l['taux_tva'] / (100 + (float) $l['taux_tva']));
                $l['tva'] = $tvaLigne;
                $tva += $tvaLigne;
            }
            unset($l);

            $numero = (int) Vente::withoutBoutiqueScope()->where('boutique_id', $boutiqueId)->max('numero') + 1;

            $vente = Vente::create([
                'user_id' => $caissier->id,
                'client_id' => $data['client_id'] ?? null,
                'reference_locale' => $data['reference_locale'] ?? null,
                'numero' => $numero,
                'sous_total' => $sousTotal,
                'remise' => $remise,
                'tva' => $tva,
                'total' => $total,
                'moyen_paiement' => $moyenPaiement,
                'montant_recu' => $montantRecu,
                'monnaie_rendue' => $montantRecu !== null ? $montantRecu - $total : null,
                'statut' => 'validee',
                'vendue_hors_ligne' => $data['vendue_hors_ligne'] ?? false,
                'synchronisee_le' => now(),
            ]);

            foreach ($lignes as $l) {
                $vente->lignes()->create([
                    'produit_id' => $l['produit']->id,
                    'nom_produit' => $l['produit']->nom,
                    'prix_unitaire' => $l['prix_unitaire'],
                    'taux_tva' => $l['taux_tva'],
                    'quantite' => $l['quantite'],
                    'total_ligne' => $l['total_ligne'],
                ]);

                $produit = $l['produit'];
                $produit->stock -= $l['quantite'];
                $produit->save();

                MouvementStock::create([
                    'produit_id' => $produit->id,
                    'user_id' => $caissier->id,
                    'vente_id' => $vente->id,
                    'type' => TypeMouvementStock::Sortie,
                    'quantite' => -$l['quantite'],
                    'stock_apres' => $produit->stock,
                    'motif' => 'Vente n°'.$vente->numeroFormate(),
                ]);
            }

            return $vente->load('lignes');
        });
    }
}
