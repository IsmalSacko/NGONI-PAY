<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Enums\TypeMouvementStock;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\MouvementStock;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\ServicePressing;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Vente;
use App\Support\Quantite;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
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
     *     lignes: list<array{produit_id?: ?string, libelle?: ?string, prix_unitaire?: ?int, taux_tva?: ?float, quantite: int|float}>,
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
            $boutique = Boutique::whereKey($boutiqueId)->lockForUpdate()->first();

            $produits = Produit::whereIn('id', array_filter(array_column($data['lignes'], 'produit_id')))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Pressing : les services demandés, et l'express pour tout le dépôt.
            $services = ServicePressing::whereIn('id', array_filter(array_column($data['lignes'], 'service_id')))->get()->keyBy('id');
            $express = (bool) ($data['express'] ?? false);

            $lignes = [];
            $sousTotal = 0;
            /** @var array<string, int|float> $besoin unités de base demandées par article */
            $besoin = [];

            // Vente en gros (boutique « au détail et en gros », offre qui
            // l'inclut) : tout le panier au prix de gros pour un client
            // revendeur ou par la bascule de la caisse — celle-ci demande le
            // droit d'accorder des remises. Sinon, ligne par ligne, à partir
            // du seuil de quantité de l'article.
            $grosPermis = $boutique->venteEnGros() && empty($data['sans_prix_de_gros'])
                && app(AbonnementService::class)->permet($boutique, Plan::VENTE_GROS);
            $revendeur = ! empty($data['client_id']) && (bool) Client::whereKey($data['client_id'])->value('revendeur');
            $grosDemande = ($data['tarif'] ?? 'detail') === 'gros';
            // « Chaque vente commence en gros » : le gros est la règle de la
            // boutique, le caissier n'a pas besoin du droit de remise.
            if ($grosPermis && $grosDemande && ! $revendeur && ! $boutique->vente_commence_en_gros && ! $caissier->can('ventes.remise')) {
                throw new HttpResponseException(response()->json(['message' => 'Vous n’avez pas le droit de vendre au prix de gros.'], 403));
            }
            $toutEnGros = $grosPermis && ($grosDemande || $revendeur);

            foreach ($data['lignes'] as $ligne) {
                // 1,250 kg à 3 500 F : 4 375 F — le total d'une ligne se compte
                // au franc près.
                $ligne['quantite'] = Quantite::normaliser($ligne['quantite']);

                if (empty($ligne['produit_id'])) {
                    $totalLigne = (int) round((int) $ligne['prix_unitaire'] * $ligne['quantite']);
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

                // Addition d'une commande de restaurant : le plat (options et
                // formule dans son nom) au prix figé à la commande. Jamais
                // fourni par une requête : la validation de la caisse l'écarte.
                if (isset($ligne['restaurant'])) {
                    $prix = (int) $ligne['prix_fige'];
                    $totalLigne = (int) round($prix * $ligne['quantite']);
                    $sousTotal += $totalLigne;
                    $lignes[] = [
                        'produit' => $produit,
                        'nom' => (string) $ligne['restaurant'],
                        'quantite' => $ligne['quantite'],
                        'unite' => null,
                        'contenance' => 1,
                        'base' => $ligne['quantite'],
                        'prix_unitaire' => $prix,
                        'prix_gros' => false,
                        'prix_detail' => null,
                        'prix_achat' => $produit->prix_achat,
                        'taux_tva' => $produit->taux_tva,
                        'total_ligne' => $totalLigne,
                    ];

                    continue;
                }

                // Pressing : l'habit dans un service, au prix classique ou express.
                // Pas de stock : une prestation ne se compte pas.
                if (! empty($ligne['service_id'])) {
                    $service = $services->get($ligne['service_id']);
                    // Retrait d'une commande de pressing : le prix figé au dépôt (jamais
                    // fourni par une requête — la validation de la caisse l'écarte).
                    $prix = isset($ligne['prix_fige']) ? (int) $ligne['prix_fige']
                        : ($service === null ? null : $produit->prixService($service->id, $express));
                    if ($prix === null) {
                        throw ValidationException::withMessages(['lignes' => ["« {$produit->nom} » ne se fait pas dans ce service."]]);
                    }
                    $totalLigne = (int) round($prix * $ligne['quantite']);
                    $sousTotal += $totalLigne;
                    $lignes[] = [
                        'produit' => $produit,
                        'nom' => $produit->nom.' · '.$service->nom,
                        'quantite' => $ligne['quantite'],
                        'unite' => null,
                        'contenance' => 1,
                        'base' => $ligne['quantite'],
                        'prix_unitaire' => $prix,
                        'prix_gros' => false,
                        'prix_detail' => null,
                        'prix_achat' => null,
                        'taux_tva' => $produit->taux_tva,
                        'total_ligne' => $totalLigne,
                        'service' => $service->nom,
                        'express' => $express,
                    ];

                    continue;
                }

                // Au détail (pharmacie) : une boîte, une plaquette ou un
                // comprimé, chacun à son prix ; le stock se compte en unités
                // de base (16 comprimés pour une boîte de 16).
                $palier = $produit->palier($ligne['palier'] ?? null);
                if ($palier === null) {
                    throw ValidationException::withMessages(['lignes' => ["« {$produit->nom} » ne se vend pas ainsi."]]);
                }
                $base = Quantite::normaliser($ligne['quantite'] * $palier['contenance']);
                $besoin[$produit->id] = Quantite::normaliser(($besoin[$produit->id] ?? 0) + $base);

                if ($boutique->suitLeStock() && round($produit->stock - $besoin[$produit->id], 3) < 0) {
                    $reste = Quantite::formater($produit->stock, $produit->unite);
                    throw ValidationException::withMessages(['lignes' => ["Stock insuffisant pour « {$produit->nom} » (reste {$reste})."]]);
                }

                $enGros = $grosPermis && $palier['prix_gros'] !== null
                    && ($toutEnGros || ($produit->seuil_gros !== null && $produit->seuil_gros > 0 && $base >= $produit->seuil_gros));
                $prix = $enGros ? $palier['prix_gros'] : $palier['prix'];
                $totalLigne = (int) round($prix * $ligne['quantite']);
                $sousTotal += $totalLigne;

                $lignes[] = [
                    'produit' => $produit,
                    'nom' => $produit->nom,
                    'quantite' => $ligne['quantite'],
                    'unite' => $palier['unite'] === '' ? null : $palier['unite'],
                    'contenance' => $palier['contenance'],
                    'base' => $base,
                    'prix_unitaire' => $prix,
                    // Le ticket barre le prix de détail à côté du prix de gros.
                    'prix_gros' => $enGros,
                    'prix_detail' => $enGros ? $palier['prix'] : null,
                    // Prix d'achat d'une unité de base : la marge se calcule
                    // sur quantité × contenance.
                    'prix_achat' => $produit->prix_achat,
                    'taux_tva' => $produit->taux_tva,
                    'total_ligne' => $totalLigne,
                ];
            }

            // Fidélité : remise calculée ici, au pourcentage de la boutique.
            // Sans programme (retiré entre-temps), la remise saisie reste.
            $programme = ($data['remise_fidelite'] ?? false) && ! empty($data['client_id'])
                ? app(Fidelite::class)->programme(Boutique::findOrFail($boutiqueId))
                : null;
            $remise = $programme !== null
                ? app(Fidelite::class)->remise($sousTotal, $programme['remise_pct'])
                : min($data['remise'] ?? 0, $sousTotal);
            $total = $sousTotal - $remise;

            $moyenPaiement = MoyenPaiement::from($data['moyen_paiement']);
            // Le crédit est le reste non payé : le client paie ce qu'il peut
            // (montant_paye, avec le moyen choisi), la différence est sa dette.
            // « Crédit client » seul : rien de payé, comme avant.
            $paye = $moyenPaiement === MoyenPaiement::CreditClient
                ? 0
                : min($total, (int) ($data['montant_paye'] ?? $total));
            $reste = $total - $paye;
            if ($reste > 0) {
                $this->autoriserCredit($data, $caissier);
            }

            $montantRecu = $moyenPaiement === MoyenPaiement::Especes ? (int) ($data['montant_recu'] ?? $paye) : null;
            if ($moyenPaiement === MoyenPaiement::Especes && $montantRecu < $paye) {
                throw ValidationException::withMessages(['montant_recu' => [
                    $reste > 0 ? 'Le montant reçu est inférieur au montant payé.' : 'Le montant reçu est inférieur au total.',
                ]]);
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

            // Le compteur de la boutique ne recule jamais : après « Repartir de
            // zéro », les ventes effacées gardent leur numéro, la suite continue.
            $numero = max((int) $boutique?->dernier_numero_vente, (int) Vente::withoutBoutiqueScope()->where('boutique_id', $boutiqueId)->max('numero')) + 1;
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
                'montant_paye' => $paye,
                // Pressing : acompte déjà encaissé au dépôt, à ne pas recompter dans la caisse.
                'acompte_deduit' => min($paye, (int) ($data['acompte_deduit'] ?? 0)),
                // Restaurant : la commande réglée par cette vente (table, numéro sur le ticket).
                'commande_restaurant_id' => $data['commande_restaurant_id'] ?? null,
                // Restaurant : paiement mixte (espèces + Orange Money…), jamais fourni par la caisse.
                'paiements' => $data['paiements'] ?? null,
                'reste_du' => $reste,
                'moyen_paiement' => $moyenPaiement,
                'montant_recu' => $montantRecu,
                'monnaie_rendue' => $montantRecu !== null ? $montantRecu - $paye : null,
                'statut' => 'validee',
                'vendue_hors_ligne' => $data['vendue_hors_ligne'] ?? false,
                'remise_fidelite' => $programme !== null,
                'tarif' => $toutEnGros ? 'gros' : 'detail',
                'synchronisee_le' => now(),
                // Pharmacie : qui a prescrit, le numéro, pour qui.
                'ordonnance' => array_filter($data['ordonnance'] ?? []) ?: null,
                'express' => $express && collect($lignes)->contains(fn ($l) => isset($l['service'])),
            ]);

            foreach ($lignes as $l) {
                $vente->lignes()->create([
                    'produit_id' => $l['produit']?->id,
                    'nom_produit' => $l['nom'],
                    'prix_unitaire' => $l['prix_unitaire'],
                    'prix_achat' => $l['prix_achat'] ?? null,
                    'taux_tva' => $l['taux_tva'],
                    'quantite' => $l['quantite'],
                    'unite' => $l['unite'] ?? null,
                    'contenance' => $l['contenance'] ?? 1,
                    'prix_gros' => $l['prix_gros'] ?? false,
                    'prix_detail' => $l['prix_detail'] ?? null,
                    // Le lot qui périme le plus tôt part le premier.
                    'lots' => $l['produit'] === null || isset($l['service']) ? null : (app(Lots::class)->prelever($l['produit'], $l['base']) ?: null),
                    'total_ligne' => $l['total_ligne'],
                    'service' => $l['service'] ?? null,
                    'express' => $l['express'] ?? false,
                ]);

                // Ligne libre, ou prestation d'un pressing : ni stock ni mouvement à écrire.
                if ($l['produit'] === null || isset($l['service']) || ! $boutique->suitLeStock()) {
                    continue;
                }

                $produit = $l['produit'];
                $produit->stock = Quantite::normaliser($produit->stock - $l['base']);
                $produit->save();

                MouvementStock::create([
                    'produit_id' => $produit->id,
                    'user_id' => $caissier->id,
                    'vente_id' => $vente->id,
                    'type' => TypeMouvementStock::Sortie,
                    'quantite' => -$l['base'],
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
            if ($vente->reste_du > 0 && $vente->client_id !== null) {
                $client = Client::find($vente->client_id);
                if ($client !== null && $client->soldeDu() < $vente->reste_du) {
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

            // Le stock ne revient que là où la vente l'avait pris : pas pour
            // les prestations d'un pressing, même s'il a changé d'activité depuis.
            $sortis = MouvementStock::where('vente_id', $vente->id)->where('type', TypeMouvementStock::Sortie)->pluck('produit_id')->all();
            foreach ($vente->lignes()->whereNotNull('produit_id')->get() as $ligne) {
                $produit = in_array($ligne->produit_id, $sortis, true) ? Produit::whereKey($ligne->produit_id)->lockForUpdate()->first() : null;
                if ($produit === null) {
                    continue;
                }
                $base = Quantite::normaliser($ligne->quantite * ($ligne->contenance ?: 1));
                $produit->stock = Quantite::normaliser($produit->stock + $base);
                $produit->save();
                app(Lots::class)->rendre($ligne->lots);

                MouvementStock::create([
                    'produit_id' => $produit->id,
                    'user_id' => $auteur->id,
                    'vente_id' => $vente->id,
                    'type' => TypeMouvementStock::Entree,
                    'quantite' => $base,
                    'stock_apres' => $produit->stock,
                    'motif' => 'Annulation vente '.$vente->numeroFormate(),
                ]);
            }

            return $vente->load(['lignes', 'client', 'caissier']);
        });
    }

    /**
     * Il reste à payer : on doit savoir qui doit, et le vendeur doit avoir le
     * droit (Permissions::DROITS) et l'offre (Plan::VENTE_CREDIT) de faire
     * crédit. Une vente rejouée après une coupure est refusée de même.
     *
     * @param  array<string, mixed>  $data
     */
    private function autoriserCredit(array $data, User $caissier): void
    {
        if (empty($data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => ['Choisissez le client qui paiera plus tard.']]);
        }
        abort_unless($caissier->can('ventes.credit'), 403, 'Vous n’avez pas le droit de vendre à crédit.');
        abort_unless(
            app(AbonnementService::class)->permet(Boutique::find(app(TenantContext::class)->boutiqueId()), Plan::VENTE_CREDIT),
            403,
            'La vente à crédit n’est pas incluse dans votre offre.',
        );
    }
}
