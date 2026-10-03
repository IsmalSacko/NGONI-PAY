<?php

declare(strict_types=1);

namespace App\Livewire\Categories;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\CategorieProduit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public string $nom = '';

    public string $couleur = '';

    public function mount(): void
    {
        $this->couleur = CategorieProduit::couleurLibre();
    }

    public ?string $categorieId = null;

    public function modifier(string $categorieId): void
    {
        $categorie = CategorieProduit::findOrFail($categorieId);
        $this->categorieId = $categorie->id;
        $this->nom = $categorie->nom;
        $this->couleur = strtoupper((string) ($categorie->couleur ?: CategorieProduit::couleurLibre()));
    }

    public function annuler(): void
    {
        $this->reset(['categorieId', 'nom']);
        $this->couleur = CategorieProduit::couleurLibre();
    }

    public function enregistrer(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can($this->categorieId ? 'categories.update' : 'categories.create') || abort(403);

        $data = $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'couleur' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if ($this->categorieId) {
            CategorieProduit::findOrFail($this->categorieId)->update($data);
        } else {
            CategorieProduit::create($data + ['ordre' => CategorieProduit::count()]);
        }

        $this->annuler();
    }

    public function supprimer(string $categorieId): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can('categories.delete') || abort(403);

        CategorieProduit::findOrFail($categorieId)->delete();
    }

    public function render()
    {
        return view('livewire.categories.index', [
            'categories' => CategorieProduit::withCount('produits')->orderBy('ordre')->orderBy('nom')->get(),
        ]);
    }
}
