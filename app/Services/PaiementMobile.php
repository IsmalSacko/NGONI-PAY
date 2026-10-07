<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paiement en ligne des abonnements : aiguille vers le bon prestataire selon
 * le pays de la boutique.
 *
 * - Côte d'Ivoire : Jèko (Wave, Orange, MTN, Moov, Djamo, 1,5 %).
 * - Ailleurs : FedaPay (le Mobile Money de son pays s'il y en a, et la carte).
 *
 * Le parcours est le même : la commission est ajoutée au prix, le commerçant
 * paie sur la page du prestataire, le paiement est relu chez lui avant
 * d'activer l'abonnement, et une tentative sans paiement s'annule après 30 min.
 */
class PaiementMobile
{
    public function __construct(
        private readonly PaiementJeko $jeko,
        private readonly PaiementFedapay $fedapay,
        private readonly PaiementPawapay $pawapay,
        private readonly AbonnementService $abonnements,
    ) {}

    /**
     * Les moyens proposés à cette boutique, chacun avec sa commission.
     *
     * @return list<array{code: string, libelle: string, frais_pourcentage: float}>
     */
    public function moyensPour(?Boutique $boutique): array
    {
        if ($this->jeko->proposeA($boutique)) {
            return collect(config('jeko.moyens'))
                ->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle, 'frais_pourcentage' => PaiementJeko::taux($code)])
                ->values()->all();
        }

        $prestataire = $this->pawapay->proposeA($boutique) ? $this->pawapay : $this->fedapay;

        return collect($prestataire->moyensPour($boutique))
            ->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle, 'frais_pourcentage' => $prestataire::taux($code)])
            ->values()->all();
    }

    /** @return array{demande: DemandeAbonnement, redirect_url: string} */
    public function demarrer(Boutique $boutique, User $demandeur, string $plan, CycleFacturation $cycle, string $moyen): array
    {
        if ($this->jeko->proposeA($boutique)) {
            return $this->jeko->demarrer($boutique, $demandeur, $plan, $cycle, $moyen);
        }

        // pawaPay là où il opère (Sénégal, Burkina, Bénin), FedaPay ailleurs.
        [$prestataire, $colonne, $prefixe] = $this->pawapay->proposeA($boutique)
            ? [$this->pawapay, 'pawapay_deposit_id', 'pawapay_']
            : [$this->fedapay, 'fedapay_transaction_id', 'fedapay_'];
        $moyens = $prestataire->moyensPour($boutique);
        if ($moyens === []) {
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne n’est pas disponible pour votre boutique.']]);
        }
        if (! array_key_exists($moyen, $moyens)) {
            throw ValidationException::withMessages(['moyen' => ['Choisissez l’un des moyens de paiement proposés.']]);
        }

        // Une tentative précédente restée en route : payée entre-temps, elle
        // s'active ; sinon elle est annulée et laisse la place.
        foreach (DemandeAbonnement::where('user_id', $boutique->proprietaire_id)->enAttente()->whereNotNull($colonne)->get() as $ancienne) {
            if ($this->verifier($ancienne)->statut === StatutDemande::EnAttente) {
                $ancienne->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement en ligne non terminé']);
            }
        }

        $demande = $this->abonnements->soumettre($boutique, $demandeur, $plan, $cycle, moyen: $prefixe.$moyen, prevenir: false);
        if ($demande->devise !== 'XOF') {
            $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Devise non prise en charge']);
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne n’accepte que le franc CFA (XOF).']]);
        }

        $demande->update(['frais_mobile' => PaiementJeko::fraisAuTaux($demande->montant, $prestataire::taux($moyen))]);
        try {
            $url = $prestataire === $this->pawapay ? $this->pawapay->creer($demande, $moyen) : $this->fedapay->creer($demande, $moyen, $demandeur);
        } catch (ValidationException $e) {
            $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement en ligne indisponible']);
            throw $e;
        }

        return ['demande' => $demande->fresh(), 'redirect_url' => $url];
    }

    /** Relit le paiement chez son prestataire et en tire la conséquence (voir PaiementJeko::verifier). */
    public function verifier(DemandeAbonnement $demande): DemandeAbonnement
    {
        if ($demande->jeko_paiement_id !== null) {
            return $this->jeko->verifier($demande);
        }
        if (($demande->fedapay_transaction_id === null && $demande->pawapay_deposit_id === null) || $demande->statut->estTranchee()) {
            return $demande;
        }

        $statut = $demande->pawapay_deposit_id !== null ? $this->pawapay->statut($demande) : $this->fedapay->statut($demande);
        if ($statut === null) {
            return $demande;
        }

        return DB::transaction(function () use ($demande, $statut): DemandeAbonnement {
            $demande = DemandeAbonnement::lockForUpdate()->findOrFail($demande->id);
            if ($demande->statut->estTranchee()) {
                return $demande;
            }

            if ($statut === 'refusee') {
                $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement en ligne refusé ou annulé']);

                return $demande;
            }

            $moyen = $demande->libellePaiementEnLigne() ?? 'paiement en ligne';
            $abonnement = $this->abonnements->approuver($demande, null, "Payé par {$moyen} (".($demande->pawapay_deposit_id ?? $demande->fedapay_transaction_id).')');
            $this->prevenirExploitant($demande, $moyen, $abonnement->fin?->format('d/m/Y'));

            return $demande->fresh();
        });
    }

    /**
     * Filet de sécurité (planifié) : les paiements en ligne restés en attente
     * sont relus chez leur prestataire. Sans paiement après le délai (30 min),
     * la demande est annulée avec son motif, et le commerçant prévenu.
     */
    public function verifierEnAttente(): int
    {
        $demandes = DemandeAbonnement::enAttente()
            ->where(fn ($q) => $q->whereNotNull('jeko_paiement_id')->orWhereNotNull('fedapay_transaction_id')->orWhereNotNull('pawapay_deposit_id'))
            ->get();

        foreach ($demandes as $demande) {
            $demande = $this->verifier($demande);
            if ($demande->statut === StatutDemande::EnAttente && $demande->created_at->lt(now()->subMinutes((int) config('jeko.delai_minutes')))) {
                $this->annulerFauteDePaiement($demande);
            }
        }

        return $demandes->count();
    }

    private function annulerFauteDePaiement(DemandeAbonnement $demande): void
    {
        $delai = (int) config('jeko.delai_minutes');
        $motif = "Paiement en ligne non reçu dans les {$delai} minutes : demande annulée automatiquement.";
        $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => $motif]);

        $proprietaire = User::find($demande->user_id);
        if ($proprietaire !== null) {
            $plan = Plan::parCode($demande->plan)?->nom ?? $demande->plan;
            app(NotifierCompte::class)->envoyer(
                $proprietaire,
                'Paiement de l’abonnement non reçu',
                "Nous n’avons pas reçu le paiement de votre abonnement {$plan} dans les {$delai} minutes : "
                    .'la demande est annulée et rien n’a été prélevé. Vous pouvez réessayer depuis la page Abonnement.',
                email: false,
            );
        }
    }

    private function prevenirExploitant(DemandeAbonnement $demande, string $moyen, ?string $fin): void
    {
        $plan = Plan::parCode($demande->plan)?->nom ?? $demande->plan;
        app(AlertesExploitant::class)->envoyer(
            'Abonnement payé en ligne',
            ($demande->boutique?->nom ?? 'Une boutique').' · '.$plan.' · '.number_format($demande->montant + $demande->frais_mobile, 0, ',', ' ').' F par '.$moyen
                .($demande->frais_mobile > 0 ? ' (dont '.number_format($demande->frais_mobile, 0, ',', ' ').' F de frais)' : '')
                .($fin ? ' · jusqu’au '.$fin : ''),
            '/console/demandes',
        );
    }
}
