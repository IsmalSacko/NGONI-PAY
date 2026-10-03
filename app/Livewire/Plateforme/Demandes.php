<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\DemandeAbonnement;
use App\Services\AbonnementService;
use App\Services\RechercheDemandes;
use Illuminate\Support\Facades\Auth;
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

    public function approuver(int $id, AbonnementService $service): void
    {
        $this->reset(['info', 'alerte']);
        $demande = DemandeAbonnement::with('proprietaire')->findOrFail($id);

        try {
            $abonnement = $service->approuver($demande, Auth::user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();

            return;
        }

        $this->info = "Demande approuvée : {$demande->proprietaire?->name} est en {$abonnement->plan}"
            .($abonnement->fin ? ' jusqu’au '.$abonnement->fin->format('d/m/Y') : ' sans échéance').'.';
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
        } catch (\Illuminate\Validation\ValidationException $e) {
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
