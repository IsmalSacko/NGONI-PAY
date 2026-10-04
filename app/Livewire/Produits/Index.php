<?php

declare(strict_types=1);

namespace App\Livewire\Produits;

use App\Http\Controllers\Api\UniteController;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\CategorieProduit;
use App\Models\LigneVente;
use App\Models\Produit;
use App\Models\UniteBoutique;
use App\Services\Images;
use App\Services\Lots;
use App\Services\StockService;
use App\Support\Money\Montant;
use App\Support\Quantite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithFileUploads, WithPagination;

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

    /** Vente en gros : le prix de gros et dès quelle quantité il s'applique. */
    public string $prix_gros = '';

    public string $seuil_gros = '';

    /** Photo choisie pour l'article (fichier temporaire Livewire). */
    public $photo = null;

    /** Photo actuelle à supprimer à l'enregistrement. */
    public bool $retirerPhoto = false;

    public string $taux_tva = '18';

    public string $stock = '0';

    /** Vendu à : vide = à la pièce, sinon kg, g, l, m. */
    public string $unite = '';

    public string $seuil_alerte = '10';

    /** Pharmacie : molécule, ordonnance, premier lot. */
    public string $dci = '';

    public bool $sur_ordonnance = false;

    public string $numero_lot = '';

    public string $peremption = '';

    /**
     * Vente par lot (boutique) ou au détail (pharmacie) : carton de 24,
     * boîte de 16… chacun à son prix. L'article est l'unité de base.
     *
     * @var list<array{unite: string, contenance: string, prix: string}>
     */
    public array $paliers = [];

    /** « Autre unité » : le nom d'une unité que la liste n'a pas. */
    public string $nouvelleUnite = '';

    public function ajouterUnite(): void
    {
        Auth::user()->can('produits.create') || abort(403);
        $this->resetErrorBag('nouvelleUnite');
        try {
            $data = UniteController::normaliser($this->nouvelleUnite, null);
        } catch (ValidationException $e) {
            $this->addError('nouvelleUnite', $e->errors()['nom'][0] ?? 'Unité refusée.');

            return;
        }
        UniteBoutique::create($data);
        Quantite::oublierPluriels();
        // Choisie d'office pour l'article en cours.
        $this->unite = $data['nom'];
        $this->nouvelleUnite = '';
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    public function ajouterPalier(): void
    {
        if (count($this->paliers) < 3) {
            $this->paliers[] = ['unite' => $this->estPharmacie() ? (count($this->paliers) === 0 ? 'plaquette' : 'boite') : 'carton', 'contenance' => '', 'prix' => ''];
        }
    }

    public function retirerPalier(int $i): void
    {
        unset($this->paliers[$i]);
        $this->paliers = array_values($this->paliers);
    }

    public function estPharmacie(): bool
    {
        return (bool) Boutique::find($this->boutiqueActiveId())?->estPharmacie();
    }

    public function nouveauProduit(): void
    {
        $this->resetValidation();
        $this->reset(['produitId', 'categorie_produit_id', 'nom', 'format', 'code', 'code_barre', 'prix_vente', 'prix_achat', 'prix_gros', 'seuil_gros', 'stock', 'unite', 'photo', 'retirerPhoto',
            'dci', 'sur_ordonnance', 'numero_lot', 'peremption', 'paliers']);
        $this->taux_tva = '18';
        $this->seuil_alerte = '10';
        $this->modaleOuverte = true;
    }

    public function modifier(string $produitId): void
    {
        $this->resetValidation();
        $this->reset(['photo', 'retirerPhoto']);
        $produit = Produit::findOrFail($produitId);

        $this->produitId = $produit->id;
        $this->categorie_produit_id = (string) $produit->categorie_produit_id;
        $this->nom = $produit->nom;
        $this->format = (string) $produit->format;
        $this->code = (string) $produit->code;
        $this->code_barre = (string) $produit->code_barre;
        $this->prix_vente = Montant::saisie($produit->prix_vente);
        $this->prix_achat = $produit->prix_achat === null ? '' : Montant::saisie($produit->prix_achat);
        $this->prix_gros = $produit->prix_gros === null ? '' : Montant::saisie((int) $produit->prix_gros);
        $this->seuil_gros = $produit->seuil_gros === null ? '' : Quantite::formater($produit->seuil_gros);
        $this->taux_tva = (string) $produit->taux_tva;
        $this->stock = Quantite::formater($produit->stock);
        $this->seuil_alerte = Quantite::formater($produit->seuil_alerte);
        $this->unite = (string) $produit->unite;
        $this->dci = (string) $produit->dci;
        $this->sur_ordonnance = (bool) $produit->sur_ordonnance;
        $this->paliers = collect($produit->paliers ?? [])
            ->map(fn ($p) => ['unite' => (string) $p['unite'], 'contenance' => (string) $p['contenance'], 'prix' => Montant::saisie((int) $p['prix'])])
            ->values()->all();
        $this->reset(['numero_lot', 'peremption']);
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can($this->produitId ? 'produits.update' : 'produits.create') || abort(403);

        // Refusé : la fenêtre amène le premier message rouge sous les yeux.
        try {
            $data = $this->validerFiche();
        } catch (ValidationException $e) {
            $this->dispatch('formulaire-refuse');

            throw $e;
        }

        $this->enregistrerFiche($data);
    }

    /** @return array<string, mixed> */
    private function validerFiche(): array
    {
        return $this->validate([
            // Filtrée par boutique : `exists` seul accepterait la catégorie d'une autre.
            'categorie_produit_id' => ['nullable', 'uuid', Rule::exists('categories_produits', 'id')->where('boutique_id', $this->boutiqueActiveId())->whereNull('deleted_at')],
            'nom' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:4'],
            'code_barre' => ['nullable', 'string', 'max:255', Rule::unique('produits', 'code_barre')->where('boutique_id', $this->boutiqueActiveId())->ignore($this->produitId)],
            // Saisi dans la devise (« 2,50 » en euros), stocké en unités mineures.
            'prix_vente' => ['required', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
            'prix_achat' => ['nullable', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
            'prix_gros' => ['nullable', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
            'seuil_gros' => ['nullable', 'string', 'regex:'.Quantite::REGEX_SAISIE],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'taux_tva' => ['required', 'numeric', 'min:0', 'max:100'],
            'stock' => ['required', 'string', 'regex:'.Quantite::REGEX_SAISIE],
            'seuil_alerte' => ['required', 'string', 'regex:'.Quantite::REGEX_SAISIE],
            'unite' => ['nullable', 'string', 'max:40', Quantite::regle()],
            'dci' => ['nullable', 'string', 'max:120'],
            'sur_ordonnance' => ['boolean'],
            'numero_lot' => ['nullable', 'string', 'max:60'],
            'peremption' => ['nullable', 'date_format:Y-m-d'],
            'paliers' => ['array', 'max:3'],
            'paliers.*.unite' => ['required', 'string', 'max:40', 'distinct', Quantite::regle()],
            'paliers.*.contenance' => ['required', 'integer', 'min:2', 'max:100000'],
            'paliers.*.prix' => ['required', 'string', 'regex:/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/'],
        ], [], ['paliers.*.contenance' => 'contenu', 'paliers.*.prix' => 'prix']);
    }

    /** @param  array<string, mixed>  $data */
    private function enregistrerFiche(array $data): void
    {

        $lot = ['numero' => trim((string) ($data['numero_lot'] ?? '')) ?: null, 'peremption' => ($data['peremption'] ?? '') ?: null];
        unset($data['numero_lot'], $data['peremption']);
        $data['paliers'] = collect($data['paliers'] ?? [])
            ->map(fn ($p) => ['unite' => $p['unite'], 'contenance' => (int) $p['contenance'], 'prix' => (int) Montant::parse($p['prix'])])
            ->sortBy('contenance')->values()->all() ?: null;
        $data['dci'] = trim((string) ($data['dci'] ?? '')) ?: null;

        unset($data['photo']);
        $data['categorie_produit_id'] = $data['categorie_produit_id'] ?: null;
        // Champs facultatifs vides : NULL, jamais '' — le code-barres est unique
        // par boutique, deux articles sans code entraient en conflit (erreur 500).
        foreach (['code_barre', 'code', 'format'] as $champ) {
            $data[$champ] = trim((string) ($data[$champ] ?? '')) === '' ? null : trim((string) $data[$champ]);
        }
        $data['prix_vente'] = Montant::parse($data['prix_vente']);
        $data['prix_achat'] = Montant::parse($data['prix_achat'] ?? null);
        // Vente en gros : seulement dans une boutique « au détail et en gros ».
        if (Boutique::find($this->boutiqueActiveId())?->venteEnGros()) {
            $data['prix_gros'] = Montant::parse($data['prix_gros'] ?? null);
            $data['seuil_gros'] = trim((string) ($data['seuil_gros'] ?? '')) === '' ? null : Quantite::lire($data['seuil_gros']);
        } else {
            unset($data['prix_gros'], $data['seuil_gros']);
        }
        $data['stock'] = Quantite::lire($data['stock']) ?? 0;
        $data['seuil_alerte'] = Quantite::lire($data['seuil_alerte']) ?? 0;
        $data['unite'] = ($data['unite'] ?? '') ?: null;

        if ($this->produitId) {
            // Le stock passe par StockService (journalisé), jamais par un
            // simple update : voir ProduitController::update côté API.
            $nouveauStock = $data['stock'];
            unset($data['stock']);

            $produit = Produit::findOrFail($this->produitId);
            // Unité figée dès la première vente : voir ProduitController::update.
            if ($data['unite'] !== $produit->unite && LigneVente::where('produit_id', $produit->id)->exists()) {
                $this->addError('unite', 'Cet article a déjà été vendu : sa façon de se vendre ne change plus. Créez un nouvel article.');
                $this->dispatch('formulaire-refuse');

                return;
            }
            $produit->update($data);

            if ($nouveauStock != $produit->stock) {
                Auth::user()->can('stocks.update') || abort(403);
                app(StockService::class)->ajuster($produit, $nouveauStock, Auth::user(), 'Modification de la fiche article');
            }
        } else {
            $produit = Produit::create($data);
            // Pharmacie : le stock de départ forme le premier lot.
            app(Lots::class)->entrer($produit, $produit->stock ?? 0, $lot['numero'], $lot['peremption']);
        }

        $images = app(Images::class);
        if ($this->photo) {
            $produit->forceFill(['photo' => $images->enregistrer($this->photo, 'produits', $produit->id, $produit->photo)])->save();
        } elseif ($this->retirerPhoto && $produit->photo) {
            $images->supprimer($produit->photo);
            $produit->forceFill(['photo' => null])->save();
        }
        $this->reset(['photo', 'retirerPhoto']);

        $this->modaleOuverte = false;
    }

    /** « Retirer » : annule la photo choisie, ou marque l'actuelle à supprimer. */
    public function retirerLaPhoto(): void
    {
        if ($this->photo) {
            $this->reset('photo');

            return;
        }
        $this->retirerPhoto = true;
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
            ->when(trim($this->recherche) !== '', fn ($q) => $q->where(fn ($q) => $q->where('nom', 'like', '%'.trim($this->recherche).'%')
                ->orWhere('code_barre', 'like', '%'.trim($this->recherche).'%')->orWhere('dci', 'like', '%'.trim($this->recherche).'%')))
            ->orderBy('nom')
            ->paginate(20);

        return view('livewire.produits.index', [
            'unitesPerso' => UniteBoutique::orderBy('nom')->pluck('nom'),
            'produits' => $produits,
            'categories' => CategorieProduit::orderBy('nom')->get(),
            'marge' => $this->marge(),
            // En tête, comme l'application : le catalogue en un chiffre.
            'nArticles' => Produit::where('actif', true)->count(),
            'nSansPhoto' => Produit::where('actif', true)->whereNull('photo')->count(),
            'nSansPrixAchat' => Produit::where('actif', true)->whereNull('prix_achat')->count(),
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
