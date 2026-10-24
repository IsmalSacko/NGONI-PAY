<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Client;
use App\Models\Vente;
use App\Services\ReglementsCredit;
use App\Support\Money\Montant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithPagination;

    public string $recherche = '';

    public bool $modaleOuverte = false;

    public ?string $clientId = null;

    public string $nom = '';

    public string $telephone = '';

    /** Vente en gros : ses achats passent d'eux-mêmes au prix de gros. */
    public bool $revendeur = false;

    public function nouveauClient(): void
    {
        $this->resetValidation();
        $this->reset(['clientId', 'nom', 'telephone', 'revendeur']);
        $this->modaleOuverte = true;
    }

    public function modifier(string $clientId): void
    {
        $this->resetValidation();
        $client = Client::findOrFail($clientId);
        $this->clientId = $client->id;
        $this->nom = $client->nom;
        $this->telephone = (string) $client->telephone;
        $this->revendeur = (bool) $client->revendeur;
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can($this->clientId ? 'clients.update' : 'clients.create') || abort(403);

        $data = $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'revendeur' => ['boolean'],
        ]);

        if ($this->clientId) {
            Client::findOrFail($this->clientId)->update($data);
        } else {
            Client::create($data);
        }

        $this->modaleOuverte = false;
    }

    /** Règlement d'une dette (ventes à crédit). */
    public ?string $reglementClientId = null;

    public string $reglementMontant = '';

    public string $reglementMoyen = 'especes';

    public function ouvrirReglement(string $clientId): void
    {
        $this->resetValidation();
        $this->reglementClientId = $clientId;
        // Sa dette proposée ; sans dette, à saisir (le versement devient son avoir).
        $doit = Client::findOrFail($clientId)->soldeDu();
        $this->reglementMontant = $doit > 0 ? Montant::saisie($doit) : '';
        $this->reglementMoyen = 'especes';
    }

    public function enregistrerReglement(): void
    {
        // Encaisser une dette reste possible sans abonnement, comme la caisse.
        Auth::user()->can('ventes.create') || abort(403);
        $this->resetValidation();

        $client = Client::findOrFail($this->reglementClientId);
        $montant = Montant::parse($this->reglementMontant);

        // Au-delà de la dette, le reste devient son avoir.
        if ($montant === null || $montant <= 0) {
            $this->addError('reglementMontant', 'Indiquez un montant.');

            return;
        }

        // Le même chemin que l'application : reçu numéroté, solde avant et après, séance de caisse.
        app(ReglementsCredit::class)->enregistrer(
            $client,
            Auth::user(),
            $montant,
            in_array($this->reglementMoyen, ['especes', 'orange_money', 'moov_money', 'wave', 'carte', 'virement'], true) ? $this->reglementMoyen : 'especes',
        );
        $this->reglementClientId = null;
    }

    public function supprimer(string $clientId): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can('clients.delete') || abort(403);

        Client::findOrFail($clientId)->delete();
    }

    /** Tous, « Ils vous doivent » (debiteurs) ou « Vous leur gardez » (avoirs). */
    public string $filtre = 'tous';

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Ce que doit chaque client : restes dus des ventes moins ses remboursements.
        $dette = '(COALESCE((SELECT SUM(v.reste_du) FROM ventes v WHERE v.client_id = clients.id AND v.statut = ?), 0)'
            .' - COALESCE((SELECT SUM(r.montant) FROM reglements_credit r WHERE r.client_id = clients.id), 0))';
        $clients = Client::avecSoldeDu()
            ->when(trim($this->recherche) !== '', fn ($q) => $q->where(fn ($q) => $q->where('nom', 'like', '%'.trim($this->recherche).'%')
                ->orWhere('telephone', 'like', '%'.trim($this->recherche).'%')))
            // « Ils vous doivent » : les plus grosses dettes d'abord, comme l'application.
            ->when($this->filtre === 'debiteurs', fn ($q) => $q->whereRaw("$dette > 0", [Vente::STATUT_VALIDEE])
                ->reorder()->orderByRaw("$dette DESC", [Vente::STATUT_VALIDEE]))
            // « Vous leur gardez » : ceux à qui la boutique garde le plus d'argent d'abord.
            ->when($this->filtre === 'avoirs', fn ($q) => $q->whereRaw("$dette < 0", [Vente::STATUT_VALIDEE])
                ->reorder()->orderByRaw("$dette ASC", [Vente::STATUT_VALIDEE]))
            ->orderBy('nom')
            ->paginate(20);

        $soldes = Client::avecSoldeDu()->get()->map(fn ($c) => (int) $c->credit_total - (int) $c->reglements_total);

        return view('livewire.clients.index', [
            'clients' => $clients,
            'nClients' => $soldes->count(),
            'nDebiteurs' => $soldes->filter(fn (int $s) => $s > 0)->count(),
            'encours' => (int) $soldes->filter(fn (int $s) => $s > 0)->sum(),
            // L'argent des clients gardé par la boutique (avoirs).
            'avoirsTotal' => (int) -$soldes->filter(fn (int $s) => $s < 0)->sum(),
            'nAvoirs' => $soldes->filter(fn (int $s) => $s < 0)->count(),
        ]);
    }
}
