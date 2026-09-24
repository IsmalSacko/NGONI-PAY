<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Client;
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

    public function nouveauClient(): void
    {
        $this->resetValidation();
        $this->reset(['clientId', 'nom', 'telephone']);
        $this->modaleOuverte = true;
    }

    public function modifier(string $clientId): void
    {
        $this->resetValidation();
        $client = Client::findOrFail($clientId);
        $this->clientId = $client->id;
        $this->nom = $client->nom;
        $this->telephone = (string) $client->telephone;
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        Auth::user()->can($this->clientId ? 'clients.update' : 'clients.create') || abort(403);

        $data = $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ]);

        if ($this->clientId) {
            Client::findOrFail($this->clientId)->update($data);
        } else {
            Client::create($data);
        }

        $this->modaleOuverte = false;
    }

    public function supprimer(string $clientId): void
    {
        Auth::user()->can('clients.delete') || abort(403);

        Client::findOrFail($clientId)->delete();
    }

    public function render()
    {
        $clients = Client::when($this->recherche, fn ($q) => $q->where('nom', 'like', "%{$this->recherche}%")
            ->orWhere('telephone', 'like', "%{$this->recherche}%"))
            ->orderBy('nom')
            ->paginate(15);

        return view('livewire.clients.index', ['clients' => $clients]);
    }
}
