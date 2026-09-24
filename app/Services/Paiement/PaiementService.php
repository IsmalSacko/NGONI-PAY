<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\FournisseurPaiement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Paiement;
use App\Models\User;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Paiement en ligne optionnel, posé AU-DESSUS du système déclaratif : la
 * caisse continue d'enregistrer les ventes comme avant ; ce service ajoute
 * seulement « faire payer le client chez PayPal/PayDunya, puis enregistrer la
 * vente quand l'argent est confirmé ».
 *
 * La vente est créée par le serveur (et non par la tablette) dès que le
 * fournisseur confirme, via {@see VenteService::encaisser()} avec l'identifiant
 * du paiement comme `reference_locale` : tablette éteinte, appli fermée ou
 * webhook rejoué, le client qui a payé n'obtient jamais zéro vente ni deux.
 */
class PaiementService
{
    /** Le paiement en ligne n'a de sens qu'en FCFA (conversion PayPal, PayDunya en XOF). */
    private const DEVISES = ['XOF', 'XAF'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly VenteService $ventes,
        private readonly PasserellesDePaiement $passerelles,
    ) {}

    /**
     * @return list<array{moyen_paiement: string, fournisseur: string, mode: string}>
     */
    public function disponibles(User $user): array
    {
        return in_array($user->boutique?->devise, self::DEVISES, true) ? $this->passerelles->disponibles() : [];
    }

    /**
     * @param  array{
     *     reference_locale?: ?string,
     *     client_id?: ?string,
     *     lignes: list<array{produit_id: string, quantite: int}>,
     *     remise?: ?int,
     *     moyen_paiement: string,
     * }  $data
     *
     * @throws ValidationException
     * @throws PasserelleIndisponible
     */
    public function initier(array $data, User $caissier): Paiement
    {
        if (! empty($data['reference_locale'])) {
            $existant = Paiement::where('reference_locale', $data['reference_locale'])->first();

            if ($existant !== null) {
                return $existant;
            }
        }

        $moyen = MoyenPaiement::from($data['moyen_paiement']);
        $passerelle = in_array($caissier->boutique?->devise, self::DEVISES, true) ? $this->passerelles->pourMoyen($moyen) : null;

        if ($passerelle === null) {
            throw ValidationException::withMessages(['moyen_paiement' => ["« {$moyen->label()} » n'est pas disponible en paiement en ligne."]]);
        }

        $remise = (int) ($data['remise'] ?? 0);
        $total = $this->ventes->totalPanier($data['lignes'], $remise);

        if ($total <= 0) {
            throw ValidationException::withMessages(['lignes' => ['Le total à payer doit être supérieur à zéro.']]);
        }

        $facture = $passerelle->montantFacture($total);

        $paiement = Paiement::create([
            'user_id' => $caissier->id,
            'client_id' => $data['client_id'] ?? null,
            'reference_locale' => $data['reference_locale'] ?? null,
            'fournisseur' => $passerelle->fournisseur(),
            'moyen_paiement' => $moyen,
            'statut' => StatutPaiement::EnAttente,
            'montant' => $total,
            'remise' => $remise,
            'devise_fournisseur' => $facture['devise'],
            'montant_fournisseur' => $facture['montant'],
            'lignes' => array_map(fn (array $l) => ['produit_id' => $l['produit_id'], 'quantite' => (int) $l['quantite']], $data['lignes']),
        ]);

        try {
            $session = $passerelle->creer(
                $paiement,
                $this->urlPublique(route('paiements.retour', $paiement, false)),
                $this->urlPublique(route('paiements.retour', ['paiement' => $paiement, 'annule' => 1], false)),
                $this->urlPublique('/api/webhooks/'.$passerelle->fournisseur()->value),
            );
        } catch (PasserelleIndisponible $e) {
            $paiement->update(['statut' => StatutPaiement::Echoue, 'erreur' => $e->getMessage()]);

            throw $e;
        }

        $paiement->update(['reference_fournisseur' => $session->reference, 'url_paiement' => $session->url]);

        return $paiement;
    }

    /**
     * Ramène le paiement à son état réel : relit le fournisseur tant que
     * l'argent n'est pas confirmé, puis crée la vente si elle manque.
     * Idempotent — appelable en boucle (tablette), par le client (page de
     * retour) et par les webhooks, dans n'importe quel ordre.
     */
    public function synchroniser(Paiement $paiement): Paiement
    {
        if ($paiement->statut->aVerifierChezLeFournisseur() && $paiement->reference_fournisseur !== null) {
            try {
                $etat = $this->passerelles->pour($paiement->fournisseur)->consulter($paiement);
                $paiement = $this->appliquer($paiement, $etat);
            } catch (PasserelleIndisponible $e) {
                // Un fournisseur momentanément injoignable ne doit pas faire
                // échouer une relecture : le paiement garde son état, on
                // retentera au prochain passage.
                Log::info('Relecture du paiement reportée', ['paiement' => $paiement->id, 'raison' => $e->getMessage()]);
            }
        }

        if ($paiement->statut === StatutPaiement::Confirme && $paiement->vente_id === null) {
            $paiement = $this->creerLaVente($paiement);
        }

        return $paiement->load(['vente.lignes', 'vente.client', 'vente.caissier']);
    }

    /**
     * Abandon par le caissier. Le client a pu payer entre-temps : on relit
     * d'abord le fournisseur, et un paiement déjà confirmé n'est pas annulé.
     */
    public function annuler(Paiement $paiement): Paiement
    {
        $paiement = $this->synchroniser($paiement);

        if ($paiement->statut === StatutPaiement::EnAttente) {
            $paiement->update(['statut' => StatutPaiement::Annule]);
        }

        return $paiement;
    }

    /**
     * Point d'entrée des webhooks : retrouve le paiement (par la référence du
     * fournisseur, à défaut par son identifiant) puis le resynchronise auprès
     * du fournisseur — le contenu du webhook n'est qu'un déclencheur, jamais
     * une preuve.
     */
    public function traiterNotification(FournisseurPaiement $fournisseur, ?string $reference, ?string $paiementId = null): ?Paiement
    {
        $requete = Paiement::withoutBoutiqueScope()->where('fournisseur', $fournisseur->value);

        $paiement = $reference !== null && $reference !== ''
            ? (clone $requete)->where('reference_fournisseur', $reference)->first()
            : null;

        $paiement ??= $paiementId !== null && $paiementId !== '' ? (clone $requete)->whereKey($paiementId)->first() : null;

        return $paiement === null ? null : $this->synchroniser($paiement);
    }

    private function appliquer(Paiement $paiement, EtatPaiement $etat): Paiement
    {
        return DB::transaction(function () use ($paiement, $etat): Paiement {
            $courant = Paiement::withoutBoutiqueScope()->whereKey($paiement->id)->lockForUpdate()->firstOrFail();

            // Quelqu'un d'autre (webhook, autre poll) l'a déjà tranché.
            if (! $courant->statut->aVerifierChezLeFournisseur()) {
                return $courant;
            }

            // Un paiement abandonné à la caisse ne redevient utile que s'il a
            // en fait été payé.
            if ($courant->statut === StatutPaiement::Annule && $etat->statut !== StatutPaiement::Confirme) {
                return $courant;
            }

            $courant->statut = $etat->statut;
            $courant->erreur = $etat->statut === StatutPaiement::Echoue ? $etat->detail : $courant->erreur;
            $courant->confirme_le = $etat->statut === StatutPaiement::Confirme ? now() : null;
            $courant->save();

            return $courant;
        });
    }

    /**
     * Crée la vente d'un paiement confirmé. Si elle ne peut pas l'être (stock
     * épuisé pendant que le client payait, prix modifié, article supprimé), le
     * paiement reste `confirme` avec son `erreur` : l'argent est chez le
     * commerçant, il faut un traitement manuel plutôt qu'une vente fausse. La
     * création est retentée à chaque relecture.
     */
    private function creerLaVente(Paiement $paiement): Paiement
    {
        $precedent = $this->tenant->boutiqueId();
        $this->tenant->setBoutique($paiement->boutique_id);

        try {
            return DB::transaction(function () use ($paiement): Paiement {
                $courant = Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail();

                if ($courant->vente_id !== null) {
                    return $courant;
                }

                try {
                    $total = $this->ventes->totalPanier($courant->lignes, $courant->remise);

                    if ($total !== $courant->montant) {
                        throw ValidationException::withMessages(['montant' => [
                            "Le total actuel du panier ({$total}) ne correspond plus au montant payé ({$courant->montant}) : les prix ont changé pendant le paiement.",
                        ]]);
                    }

                    $vente = $this->ventes->encaisser([
                        'reference_locale' => $courant->id,
                        'client_id' => $courant->client_id,
                        'lignes' => $courant->lignes,
                        'remise' => $courant->remise,
                        'moyen_paiement' => $courant->moyen_paiement->value,
                        'montant_recu' => null,
                        'vendue_hors_ligne' => false,
                    ], User::findOrFail($courant->user_id));
                } catch (ValidationException $e) {
                    $courant->update(['erreur' => implode(' ', Arr::flatten($e->errors()))]);

                    Log::warning('Paiement confirmé mais vente impossible', ['paiement' => $courant->id, 'erreur' => $courant->erreur]);

                    return $courant;
                }

                $courant->update(['vente_id' => $vente->id, 'erreur' => null]);

                return $courant;
            });
        } finally {
            $this->tenant->setBoutique($precedent);
        }
    }

    private function urlPublique(string $chemin): string
    {
        return rtrim((string) (config('paiements.url_publique') ?: config('app.url')), '/').$chemin;
    }
}
