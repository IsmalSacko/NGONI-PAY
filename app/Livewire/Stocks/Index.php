<?php

declare(strict_types=1);

namespace App\Livewire\Stocks;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\Lot;
use App\Models\Produit;
use App\Services\StockService;
use App\Support\Quantite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithPagination;

    /** Filtre ou recherche changés : retour à la première page. */
    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    public string $filtre = 'tous';

    public string $recherche = '';

    public ?string $ajustementProduitId = null;

    public string $nouveauStock = '0';

    public string $motif = '';

    public function ouvrirAjustement(string $produitId): void
    {
        $produit = Produit::findOrFail($produitId);
        $this->ajustementProduitId = $produit->id;
        $this->nouveauStock = Quantite::formater($produit->stock);
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
            'nouveauStock' => ['required', 'string', 'regex:'.Quantite::REGEX_SAISIE],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        app(StockService::class)->ajuster(
            Produit::findOrFail($this->ajustementProduitId),
            Quantite::lire($data['nouveauStock']) ?? 0,
            Auth::user(),
            $data['motif'] ?: null,
        );

        $this->fermerAjustement();
    }

    public function render()
    {
        // Nom, code-barres ou molécule ; 25 par page.
        $terme = '%'.trim($this->recherche).'%';
        $produits = Produit::with('categorie')->where('actif', true)
            ->when(trim($this->recherche) !== '', fn ($q) => $q->where(fn ($q) => $q->where('nom', 'like', $terme)->orWhere('code_barre', 'like', $terme)->orWhere('dci', 'like', $terme)))
            ->when($this->filtre === 'rupture', fn ($q) => $q->where('stock', '<=', 0))
            ->when($this->filtre === 'bas', fn ($q) => $q->where('stock', '>', 0)->whereColumn('stock', '<=', 'seuil_alerte'))
            ->orderBy('nom')
            ->paginate(25);

        $pharmacie = (bool) Boutique::find($this->boutiqueActiveId())?->estPharmacie();

        return view('livewire.stocks.index', [
            'produits' => $produits,
            'pharmacie' => $pharmacie,
            // Pharmacie : lots périmés ou qui périment sous 90 jours, le plus proche d'abord.
            'lots' => $pharmacie && $this->filtre === 'peremption'
                ? Lot::with('produit:id,nom,unite,paliers,prix_achat,prix_vente')
                    ->where('quantite', '>', 0)->whereNotNull('peremption')
                    ->where('peremption', '<=', now()->addDays(90)->toDateString())
                    ->whereHas('produit', fn ($q) => $q->where('actif', true))
                    ->orderBy('peremption')->get()
                : collect(),
            'nTous' => Produit::where('actif', true)->count(),
            'nRupture' => Produit::where('actif', true)->where('stock', '<=', 0)->count(),
            'nBas' => Produit::where('actif', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'seuil_alerte')->count(),
            'valeur' => $this->valeur(),
        ]);
    }

    /**
     * Ce que le stock a coûté, ce qu'il rapportera vendu en entier, et la
     * différence — pour qui voit les prix d'achat seulement (pas le caissier).
     * Un article sans prix d'achat compte au prix de vente, sans bénéfice.
     *
     * @return array{achat: int, vente: int, benefice: int, taux: ?int, sans_prix_achat: int}|null
     */
    private function valeur(): ?array
    {
        // Comme dans l'application : prix d'achat et chiffre d'affaires.
        if (! auth()->user()?->can('produits.update') || ! auth()->user()->can('dashboard.view')) {
            return null;
        }

        $enStock = Produit::where('actif', true)->where('stock', '>', 0);
        $achat = (int) (clone $enStock)->sum(DB::raw('stock * COALESCE(prix_achat, prix_vente)'));
        $vente = (int) (clone $enStock)->sum(DB::raw('stock * prix_vente'));

        return [
            'achat' => $achat,
            'vente' => $vente,
            'benefice' => $vente - $achat,
            'taux' => $vente === 0 ? null : (int) round(($vente - $achat) * 100 / $vente),
            'sans_prix_achat' => (clone $enStock)->whereNull('prix_achat')->count(),
        ];
    }
}
