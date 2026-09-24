<?php

declare(strict_types=1);

namespace App\Livewire\Produits;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Services\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithPagination;

    public string $recherche = '';

    public bool $modaleOuverte = false;

    public ?string $produitId = null;

    public string $categorie_produit_id = '';

    public string $nom = '';

    public string $format = '';

    public string $code = '';

    public string $code_barre = '';

    public string $prix_vente = '';

    public string $taux_tva = '18';

    public string $stock = '0';

    public string $seuil_alerte = '10';

    public function nouveauProduit(): void
    {
        $this->resetValidation();
        $this->reset(['produitId', 'categorie_produit_id', 'nom', 'format', 'code', 'code_barre', 'prix_vente', 'stock']);
        $this->taux_tva = '18';
        $this->seuil_alerte = '10';
        $this->modaleOuverte = true;
    }

    public function modifier(string $produitId): void
    {
        $this->resetValidation();
        $produit = Produit::findOrFail($produitId);

        $this->produitId = $produit->id;
        $this->categorie_produit_id = (string) $produit->categorie_produit_id;
        $this->nom = $produit->nom;
        $this->format = (string) $produit->format;
        $this->code = (string) $produit->code;
        $this->code_barre = (string) $produit->code_barre;
        $this->prix_vente = (string) $produit->prix_vente;
        $this->taux_tva = (string) $produit->taux_tva;
        $this->stock = (string) $produit->stock;
        $this->seuil_alerte = (string) $produit->seuil_alerte;
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        Auth::user()->can($this->produitId ? 'produits.update' : 'produits.create') || abort(403);

        $data = $this->validate([
            'categorie_produit_id' => ['nullable', 'uuid', 'exists:categories_produits,id'],
            'nom' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:4'],
            'code_barre' => ['nullable', 'string', 'max:255', Rule::unique('produits', 'code_barre')->where('boutique_id', Auth::user()->boutique_id)->ignore($this->produitId)],
            'prix_vente' => ['required', 'integer', 'min:0'],
            'taux_tva' => ['required', 'numeric', 'min:0', 'max:100'],
            'stock' => ['required', 'integer', 'min:0'],
            'seuil_alerte' => ['required', 'integer', 'min:0'],
        ]);

        $data['categorie_produit_id'] = $data['categorie_produit_id'] ?: null;

        if ($this->produitId) {
            // Le stock passe par StockService (journalisé), jamais par un
            // simple update : voir ProduitController::update côté API.
            $nouveauStock = (int) $data['stock'];
            unset($data['stock']);

            $produit = Produit::findOrFail($this->produitId);
            $produit->update($data);

            if ($nouveauStock !== $produit->stock) {
                Auth::user()->can('stocks.update') || abort(403);
                app(StockService::class)->ajuster($produit, $nouveauStock, Auth::user(), 'Modification de la fiche article');
            }
        } else {
            Produit::create($data);
        }

        $this->modaleOuverte = false;
    }

    public function supprimer(string $produitId): void
    {
        Auth::user()->can('produits.delete') || abort(403);

        $produit = Produit::findOrFail($produitId);
        $produit->update(['code_barre' => null]);
        $produit->delete();
    }

    public function render()
    {
        $produits = Produit::with('categorie')
            ->when($this->recherche, fn ($q) => $q->where('nom', 'like', "%{$this->recherche}%"))
            ->orderBy('nom')
            ->paginate(15);

        return view('livewire.produits.index', [
            'produits' => $produits,
            'categories' => CategorieProduit::orderBy('nom')->get(),
        ]);
    }
}
