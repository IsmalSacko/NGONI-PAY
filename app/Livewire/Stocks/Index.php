<?php

declare(strict_types=1);

namespace App\Livewire\Stocks;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Produit;
use App\Services\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public string $filtre = 'tous';

    public string $recherche = '';

    public ?string $ajustementProduitId = null;

    public string $nouveauStock = '0';

    public string $motif = '';

    public function ouvrirAjustement(string $produitId): void
    {
        $produit = Produit::findOrFail($produitId);
        $this->ajustementProduitId = $produit->id;
        $this->nouveauStock = (string) $produit->stock;
        $this->motif = '';
    }

    public function fermerAjustement(): void
    {
        $this->reset(['ajustementProduitId', 'motif']);
    }

    public function enregistrerAjustement(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can('stocks.update') || abort(403);

        $data = $this->validate([
            'nouveauStock' => ['required', 'integer', 'min:0'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        app(StockService::class)->ajuster(
            Produit::findOrFail($this->ajustementProduitId),
            (int) $data['nouveauStock'],
            Auth::user(),
            $data['motif'] ?: null,
        );

        $this->fermerAjustement();
    }

    public function render()
    {
        $produits = Produit::where('actif', true)
            ->when($this->recherche, fn ($q) => $q->where('nom', 'like', "%{$this->recherche}%"))
            ->when($this->filtre === 'rupture', fn ($q) => $q->where('stock', '<=', 0))
            ->when($this->filtre === 'bas', fn ($q) => $q->where('stock', '>', 0)->whereColumn('stock', '<=', 'seuil_alerte'))
            ->orderBy('nom')
            ->get();

        return view('livewire.stocks.index', [
            'produits' => $produits,
            'nRupture' => Produit::where('actif', true)->where('stock', '<=', 0)->count(),
            'nBas' => Produit::where('actif', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'seuil_alerte')->count(),
            'valeurStock' => (int) Produit::where('actif', true)->sum(DB::raw('stock * prix_vente')),
        ]);
    }
}
