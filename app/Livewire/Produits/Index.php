<?php

declare(strict_types=1);

namespace App\Livewire\Produits;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Services\StockService;
use App\Support\Money\Montant;
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

    public string $prix_achat = '';

    public string $taux_tva = '18';

    public string $stock = '0';

    public string $seuil_alerte = '10';

    public function nouveauProduit(): void
    {
        $this->resetValidation();
        $this->reset(['produitId', 'categorie_produit_id', 'nom', 'format', 'code', 'code_barre', 'prix_vente', 'prix_achat', 'stock']);
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
        $this->prix_vente = Montant::saisie($produit->prix_vente);
        $this->prix_achat = $produit->prix_achat === null ? '' : Montant::saisie($produit->prix_achat);
        $this->taux_tva = (string) $produit->taux_tva;
        $this->stock = (string) $produit->stock;
        $this->seuil_alerte = (string) $produit->seuil_alerte;
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can($this->produitId ? 'produits.update' : 'produits.create') || abort(403);

        $data = $this->validate([
            // Filtrée par boutique : `exists` seul accepterait la catégorie d'une autre.
            'categorie_produit_id' => ['nullable', 'uuid', Rule::exists('categories_produits', 'id')->where('boutique_id', $this->boutiqueActiveId())->whereNull('deleted_at')],
            'nom' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:4'],
            'code_barre' => ['nullable', 'string', 'max:255', Rule::unique('produits', 'code_barre')->where('boutique_id', $this->boutiqueActiveId())->ignore($this->produitId)],
            // Saisi dans la devise (« 2,50 » en euros), stocké en unités mineures.
            'prix_vente' => ['required', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
            'prix_achat' => ['nullable', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
            'taux_tva' => ['required', 'numeric', 'min:0', 'max:100'],
            'stock' => ['required', 'integer', 'min:0'],
            'seuil_alerte' => ['required', 'integer', 'min:0'],
        ]);

        $data['categorie_produit_id'] = $data['categorie_produit_id'] ?: null;
        $data['prix_vente'] = Montant::parse($data['prix_vente']);
        $data['prix_achat'] = Montant::parse($data['prix_achat'] ?? null);

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
        if (! $this->abonnementActif()) {
            return;
        }

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
            'marge' => $this->marge(),
        ]);
    }

    /** « Marge : 5 000 par article (20 %) », ou vente à perte. */
    private function marge(): ?array
    {
        $vente = Montant::parse($this->prix_vente);
        $achat = Montant::parse($this->prix_achat);
        if ($vente === null || $achat === null || $vente <= 0) {
            return null;
        }

        $marge = $vente - $achat;
        $devise = Montant::deviseActive();

        return $marge < 0
            ? ['perte' => true, 'texte' => 'Vente à perte : '.Montant::format(-$marge).' '.$devise.' par article']
            : ['perte' => false, 'texte' => 'Marge : '.Montant::format($marge).' '.$devise.' par article ('.round($marge * 100 / $vente).' %)'];
    }
}
