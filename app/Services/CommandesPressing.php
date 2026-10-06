<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandePressing;
use App\Models\EncaissementPressing;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\ServicePressing;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commandes d'un pressing : dépôt (numéro unique, prix figés, acompte),
 * linge prêt, retrait (la vente naît là, acompte compris) et annulation.
 * Réservé aux boutiques en activité pressing : les autres n'y ont pas accès.
 */
class CommandesPressing
{
    public function __construct(private readonly TenantContext $tenant, private readonly SessionCaisseService $sessions) {}

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
        $collecte = (bool) ($data['collecte'] ?? false);
        $livraison = (bool) ($data['livraison'] ?? false);
        if ($express) {
            $this->exigerPro($boutique, 'Le service express fait partie de l’offre Pro.');
        }
        if ($collecte || $livraison) {
            $this->exigerPro($boutique, 'La collecte et la livraison font partie de l’offre Pro.');
            if (! filled($data['adresse'] ?? null)) {
                throw ValidationException::withMessages(['adresse' => ['Indiquez l’adresse de collecte ou de livraison.']]);
            }
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

        return DB::transaction(function () use ($boutique, $data, $agent, $express, $collecte, $livraison, $lignes, $total, $acompte): CommandePressing {
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
                'historique' => [['quoi' => CommandePressing::DEPOSEE, 'le' => now()->toIso8601String(), 'par' => $agent->name]],
                'express' => $express,
                'collecte' => $collecte,
                'livraison' => $livraison,
                'adresse' => $collecte || $livraison ? trim((string) $data['adresse']) : null,
                'total' => $total,
                'acompte' => $acompte,
                'moyen_acompte' => $acompte > 0 ? ($data['moyen_acompte'] ?? 'especes') : null,
                // Par défaut : dans 2 jours à 20 h, le lendemain en express.
                'retrait_prevu_le' => filled($data['retrait_prevu_le'] ?? null)
                    ? $data['retrait_prevu_le'] : now()->addDays($express ? 1 : 2)->setTime(20, 0),
                'notes' => $data['notes'] ?? null,
            ]);
            $commande->lignes()->createMany($lignes);
            // L'acompte est un vrai encaissement : il entre dans la caisse du jour (pas encore une vente).
            if ($acompte > 0) {
                $this->mouvement($commande, EncaissementPressing::ACOMPTE, $acompte, $commande->moyen_acompte, $agent);
            }

            return $commande->load('lignes', 'client');
        });
    }

    /** Une étape du travail (lavage, séchage, repassage, contrôle). */
    public function etape(CommandePressing $commande, string $etape, User $agent): CommandePressing
    {
        $this->exigerOuverte($commande);
        if (! array_key_exists($etape, CommandePressing::ETAPES)) {
            throw ValidationException::withMessages(['etape' => ['Étape inconnue.']]);
        }
        $commande->update([
            'statut' => CommandePressing::EN_TRAITEMENT, 'etape' => $etape, 'prete_le' => null,
            'historique' => $commande->avecPas($etape, $agent),
        ]);

        return $commande;
    }

    /** [casier] : où le linge prêt est rangé (offre Pro). */
    public function marquerPrete(CommandePressing $commande, ?User $agent = null, ?string $casier = null): CommandePressing
    {
        $this->exigerOuverte($commande);
        if (filled($casier)) {
            $this->exigerPro($this->boutique(), 'Les casiers font partie de l’offre Pro.');
        }
        $commande->update([
            'statut' => CommandePressing::PRETE, 'etape' => null, 'prete_le' => now(),
            'casier' => filled($casier) ? trim($casier) : $commande->casier,
            'historique' => $commande->avecPas(CommandePressing::PRETE, $agent),
        ]);

        return $commande;
    }

    /**
     * Retrait : la vente naît, aux prix figés du dépôt, acompte compris ; la
     * commande est clôturée. [montant_donne] : ce que le client remet maintenant.
     * [credit] : le reste passe sur le compte du client (vente à crédit existante).
     *
     * @param  array{moyen_paiement: string, montant_donne?: ?int, credit?: bool}  $data
     */
    public function retirer(CommandePressing $commande, array $data, User $caissier): CommandePressing
    {
        $this->exigerOuverte($commande);
        $commande->loadMissing('lignes');

        $donne = $data['montant_donne'] ?? null;
        $credit = (bool) ($data['credit'] ?? false) && $commande->reste() > 0;
        // L'acompte est déjà dans la caisse (jour du dépôt) : la vente le note pour ne pas le recompter.
        $paiement = $credit
            ? ['moyen_paiement' => $commande->moyen_acompte ?? 'especes', 'montant_paye' => $commande->acompte, 'montant_recu' => $commande->acompte]
            : ['moyen_paiement' => $data['moyen_paiement'], 'montant_recu' => $donne === null ? $commande->total : $commande->acompte + (int) $donne];
        $vente = app(VenteService::class)->encaisser([
            'client_id' => $commande->client_id,
            'express' => $commande->express,
            'lignes' => $commande->lignes->map(fn ($l) => $l->produit_id !== null
                ? ['produit_id' => $l->produit_id, 'service_id' => $l->service_id, 'quantite' => $l->quantite, 'prix_fige' => $l->prix_unitaire]
                // Habit supprimé depuis : la ligne garde son nom et son prix.
                : ['libelle' => $l->nom.' · '.$l->service, 'prix_unitaire' => $l->prix_unitaire, 'quantite' => $l->quantite, 'taux_tva' => 0])->all(),
            ...$paiement,
            'acompte_deduit' => $commande->acompte,
        ], $caissier);

        $commande->update([
            'statut' => CommandePressing::RETIREE, 'etape' => null, 'retiree_le' => now(), 'retiree_par' => $caissier->id, 'vente_id' => $vente->id,
            // Livrée chez le client, ou retirée au comptoir.
            'historique' => $commande->avecPas($commande->livraison ? 'livree' : CommandePressing::RETIREE, $caissier),
        ]);

        return $commande;
    }

    /** Annulation ; [rembourser] : l'acompte est rendu au client et sort de la caisse du jour. */
    public function annuler(CommandePressing $commande, ?string $motif, bool $rembourser = false, ?User $agent = null): CommandePressing
    {
        $this->exigerOuverte($commande);
        DB::transaction(function () use ($commande, $motif, $rembourser, $agent): void {
            if ($rembourser && $commande->acompte > 0) {
                $this->mouvement($commande, EncaissementPressing::REMBOURSEMENT, $commande->acompte, $commande->moyen_acompte ?? 'especes', $agent);
            }
            $commande->update([
                'statut' => CommandePressing::ANNULEE, 'etape' => null, 'annulee_le' => now(), 'motif_annulation' => $motif,
                'historique' => $commande->avecPas(CommandePressing::ANNULEE, $agent),
            ]);
        });

        return $commande;
    }

    /** Casier ou rayon où le linge est rangé (offre Pro). */
    public function ranger(CommandePressing $commande, ?string $casier): CommandePressing
    {
        $this->exigerOuverte($commande);
        $this->exigerPro($this->boutique(), 'Les casiers font partie de l’offre Pro.');
        $commande->update(['casier' => filled($casier) ? trim($casier) : null]);

        return $commande;
    }

    /** Photo d'un défaut, prise au dépôt (offre Pro). */
    public function ajouterPhoto(CommandePressing $commande, UploadedFile $fichier): CommandePressing
    {
        $this->exigerPro($this->boutique(), 'Les photos des défauts font partie de l’offre Pro.');
        $photos = $commande->photos ?? [];
        if (count($photos) >= CommandePressing::PHOTOS_MAX) {
            throw ValidationException::withMessages(['photo' => ['Six photos au plus par commande.']]);
        }
        $chemin = app(Images::class)->enregistrer($fichier, "pressing/{$commande->boutique_id}", $commande->id);
        $commande->update(['photos' => [...$photos, ['chemin' => $chemin, 'le' => now()->toIso8601String()]]]);

        return $commande;
    }

    public function retirerPhoto(CommandePressing $commande, int $index): CommandePressing
    {
        $photos = $commande->photos ?? [];
        abort_unless(isset($photos[$index]), 404);
        app(Images::class)->supprimer($photos[$index]['chemin']);
        array_splice($photos, $index, 1);
        $commande->update(['photos' => $photos]);

        return $commande;
    }

    private function exigerPro(Boutique $boutique, string $message): void
    {
        if (! app(AbonnementService::class)->permet($boutique, Plan::PRESSING_AVANCE)) {
            throw new HttpResponseException(response()->json([
                'message' => $message, 'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => Plan::PRESSING_AVANCE,
            ], 403));
        }
    }

    /** Acompte ou remboursement, rattaché à la séance de caisse de l'agent (le tiroir du jour). */
    private function mouvement(CommandePressing $commande, string $type, int $montant, ?string $moyen, ?User $agent): void
    {
        EncaissementPressing::create([
            'boutique_id' => $commande->boutique_id, 'commande_id' => $commande->id, 'user_id' => $agent?->id,
            'session_caisse_id' => $agent === null ? null : $this->sessions->courante($agent)?->id,
            'type' => $type, 'montant' => $montant, 'moyen_paiement' => $moyen ?? 'especes',
        ]);
    }

    private function exigerOuverte(CommandePressing $commande): void
    {
        if (! in_array($commande->statut, CommandePressing::OUVERTES, true)) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà '.($commande->statut === CommandePressing::RETIREE ? 'retirée' : 'annulée').'.']]);
        }
    }
}
