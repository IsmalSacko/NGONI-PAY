<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Enums\StatutDemande;
use App\Models\DemandeAbonnement;
use App\Services\AbonnementService;
use App\Services\PaiementMobile;
use App\Services\RechercheDemandes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Demandes d'abonnement : l'exploitant constate le paiement (preuve jointe,
 * mobile money, espèces) puis approuve ou refuse avec un motif.
 */
#[Layout('layouts.plateforme', ['title' => 'Demandes'])]
class Demandes extends Component
{
    use WithPagination;

    /** Onglet : en_attente | approuvee | refusee | annulee | toutes. */
    #[Url(as: 'onglet')]
    public string $filtre = 'en_attente';

    /** Boutique, téléphone, nom ou e-mail du demandeur. */
    #[Url(as: 'q')]
    public string $recherche = '';

    public ?int $refusEnCours = null;

    public string $motif = '';

    public ?string $info = null;

    public ?string $alerte = null;

    public function updatingFiltre(): void
    {
        $this->resetPage();
    }

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    /** [$sansEcheance] : le commerçant a payé un accès à vie. */
    public function approuver(int $id, AbonnementService $service, bool $sansEcheance = false): void
    {
        $this->reset(['info', 'alerte']);
        $demande = DemandeAbonnement::with('proprietaire')->findOrFail($id);

        try {
            $abonnement = $service->approuver($demande, Auth::user(), sansEcheance: $sansEcheance);
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();

            return;
        }

        $this->info = "Demande approuvée : {$demande->proprietaire?->name} est en {$abonnement->plan}"
            .($abonnement->fin ? ' jusqu’au '.$abonnement->fin->format('d/m/Y') : ' sans échéance').'.';
    }

    /**
     * Ce que l'opérateur dit du paiement en ligne, à l'instant. En attente, la
     * demande en tire la conséquence (payée, elle s'active) ; tranchée, elle
     * ne change pas, et un paiement reçu après coup est signalé.
     */
    public function verifierPaiement(int $id, PaiementMobile $paiements): void
    {
        $this->reset(['info', 'alerte']);
        $demande = DemandeAbonnement::findOrFail($id);
        if (! $demande->paiementEnLigne()) {
            return;
        }

        $moyen = $demande->libellePaiementEnLigne();
        if ($demande->statut === StatutDemande::EnAttente) {
            $demande = $paiements->verifier($demande);
            if ($demande->statut === StatutDemande::Approuvee) {
                $this->info = "Paiement reçu ({$moyen}) : l’abonnement est activé.";

                return;
            }
        }

        $statut = $paiements->statutChezPrestataire($demande);
        if ($statut === 'payee' && $demande->statut !== StatutDemande::Approuvee) {
            $this->alerte = "Payé chez l’opérateur ({$moyen}) alors que la demande est ".mb_strtolower($demande->statut->libelle()).' : '
                .'l’abonnement ne s’est pas activé. Accordez-le depuis Abonnements.';

            return;
        }

        $this->info = $moyen.' : '.match ($statut) {
            'payee' => 'paiement reçu.',
            'refusee' => 'paiement refusé ou annulé chez l’opérateur, rien n’a été prélevé.',
            default => 'aucun paiement reçu (jamais validé chez l’opérateur, ou encore en cours).',
        };
    }

    public function demanderRefus(int $id): void
    {
        $this->refusEnCours = $id;
        $this->motif = '';
    }

    public function refuser(AbonnementService $service): void
    {
        $this->reset(['info', 'alerte']);
        $this->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif : le commerçant le verra.']);

        $demande = DemandeAbonnement::findOrFail($this->refusEnCours);

        try {
            $service->refuser($demande, Auth::user(), $this->motif);
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();

            return;
        }

        $this->refusEnCours = null;
        $this->info = 'Demande refusée.';
    }

    public function render()
    {
        $recherche = app(RechercheDemandes::class);
        $demandes = $recherche->requete($this->filtre, $this->recherche)->paginate(20);
        $comptes = $recherche->comptes($this->recherche);

        return view('livewire.plateforme.demandes', ['demandes' => $demandes, 'comptes' => $comptes]);
    }
}
