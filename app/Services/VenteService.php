<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Enums\TypeMouvementStock;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Encaissement d'une vente à la caisse tactile.
 *
 * Pour un article du catalogue, les prix et taux de TVA ne sont JAMAIS pris
 * depuis le client : ils sont relus depuis le catalogue au moment de
 * l'encaissement, pour qu'une tablette compromise ou désynchronisée ne puisse
 * pas imposer son propre prix. Seules les quantités viennent du client.
 *
 * Une ligne libre (libellé + prix saisi, sans produit) sert aux prestations,
 * acomptes et articles hors catalogue : son prix vient par nature du client,
 * elle ne touche ni au stock ni au journal des mouvements.
 *
 * `reference_locale` (UUID généré par la tablette) rend l'appel idempotent :
 * une vente hors ligne rejouée au retour du réseau ne se double pas.
 */
class VenteService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SessionCaisseService $sessions,
    ) {}

    /**
     * @param  array{
     *     reference_locale: ?string,
     *     client_id: ?string,
     *     lignes: list<array{produit_id?: ?string, libelle?: ?string, prix_unitaire?: ?int, taux_tva?: ?float, quantite: int}>,
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

            $produits = Produit::whereIn('id', array_filter(array_column($data['lignes'], 'produit_id')))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lignes = [];
            $sousTotal = 0;

            foreach ($data['lignes'] as $ligne) {
                if (empty($ligne['produit_id'])) {
                    $totalLigne = (int) $ligne['prix_unitaire'] * $ligne['quantite'];
                    $sousTotal += $totalLigne;

                    $lignes[] = [
                        'produit' => null,
                        'nom' => trim((string) $ligne['libelle']),
                        'quantite' => $ligne['quantite'],
                        'prix_unitaire' => (int) $ligne['prix_unitaire'],
                        // Une prestation est souvent hors TVA : 0 par défaut.
                        'taux_tva' => (float) ($ligne['taux_tva'] ?? 0),
                        'total_ligne' => $totalLigne,
                    ];

                    continue;
                }

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
                    'nom' => $produit->nom,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $produit->prix_vente,
                    'prix_achat' => $produit->prix_achat,
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
            // Journée d'affaires et numéro du jour (repart à 1 après la clôture).
            $jour = app(Journee::class)->courante()->toDateString();
            $numeroJour = (int) Vente::withoutBoutiqueScope()->where('boutique_id', $boutiqueId)->whereDate('jour_affaire', $jour)->max('numero_jour') + 1;
            $session = $this->sessions->courante($caissier);

            $vente = Vente::create([
                'user_id' => $caissier->id,
                'client_id' => $data['client_id'] ?? null,
                'session_caisse_id' => $session?->id,
                'reference_locale' => $data['reference_locale'] ?? null,
                'numero' => $numero,
                'jour_affaire' => $jour,
                'numero_jour' => $numeroJour,
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
                    'produit_id' => $l['produit']?->id,
                    'nom_produit' => $l['nom'],
                    'prix_unitaire' => $l['prix_unitaire'],
                    'prix_achat' => $l['prix_achat'] ?? null,
                    'taux_tva' => $l['taux_tva'],
                    'quantite' => $l['quantite'],
                    'total_ligne' => $l['total_ligne'],
                ]);

                // Ligne libre : ni stock ni mouvement à écrire.
                if ($l['produit'] === null) {
                    continue;
                }

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

    /**
     * Annule une vente : elle reste dans l'historique, marquée annulée, avec
     * qui, quand et pourquoi. Le stock des articles revient (mouvement
     * d'entrée tracé). Elle ne compte plus dans le chiffre d'affaires, l'écart
     * de caisse ni ce que doit un client à crédit.
     */
    public function annuler(Vente $vente, User $auteur, string $motif): Vente
    {
        return DB::transaction(function () use ($vente, $auteur, $motif): Vente {
            $vente = Vente::whereKey($vente->id)->lockForUpdate()->firstOrFail();

            if ($vente->estAnnulee()) {
                throw ValidationException::withMessages(['vente' => ['Cette vente est déjà annulée.']]);
            }

            // Des chiffres déjà arrêtés ne doivent jamais bouger après coup :
            // - une journée passée (chiffre d'affaires, rapports) ;
            $journee = app(Journee::class);
            $jourVente = $vente->jour_affaire ?? $vente->created_at->toDateString();
            if ($journee->estCloturee($jourVente) || ! Carbon::parse($jourVente)->isSameDay($journee->courante())) {
                throw ValidationException::withMessages(['vente' => [
                    'Seules les ventes de la journée en cours s’annulent : les journées clôturées ou passées sont arrêtées.',
                ]]);
            }

            // - une séance de caisse fermée (son écart a été compté) ;
            $session = $vente->session_caisse_id ? SessionCaisse::find($vente->session_caisse_id) : null;
            if ($session !== null && ! $session->estOuverte()) {
                throw ValidationException::withMessages(['vente' => [
                    'La séance de caisse de cette vente est fermée : son fond a déjà été compté.',
                ]]);
            }

            // - une dette déjà remboursée (le client aurait payé pour rien).
            if ($vente->moyen_paiement === MoyenPaiement::CreditClient && $vente->client_id !== null) {
                $client = Client::find($vente->client_id);
                if ($client !== null && $client->soldeDu() < $vente->total) {
                    throw ValidationException::withMessages(['vente' => [
                        'Le client a déjà remboursé une partie de ses achats à crédit : annuler cette vente fausserait sa dette.',
                    ]]);
                }
            }

            $vente->update([
                'statut' => Vente::STATUT_ANNULEE,
                'annulee_le' => now(),
                'annulee_par' => $auteur->id,
                'motif_annulation' => $motif,
            ]);

            foreach ($vente->lignes()->whereNotNull('produit_id')->get() as $ligne) {
                $produit = Produit::whereKey($ligne->produit_id)->lockForUpdate()->first();
                if ($produit === null) {
                    continue;
                }
                $produit->stock += $ligne->quantite;
                $produit->save();

                MouvementStock::create([
                    'produit_id' => $produit->id,
                    'user_id' => $auteur->id,
                    'vente_id' => $vente->id,
                    'type' => TypeMouvementStock::Entree,
                    'quantite' => $ligne->quantite,
                    'stock_apres' => $produit->stock,
                    'motif' => 'Annulation vente '.$vente->numeroFormate(),
                ]);
            }

            return $vente->load(['lignes', 'client', 'caissier']);
        });
    }
}
