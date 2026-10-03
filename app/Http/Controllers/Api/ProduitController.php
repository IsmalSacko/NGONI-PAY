<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Services\StockService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProduitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Produit::with('categorie')->where('actif', true);

        if ($request->filled('categorie_produit_id')) {
            $query->where('categorie_produit_id', $request->string('categorie_produit_id'));
        }

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'like', $terme)->orWhere('code_barre', 'like', $terme);
            });
        }

        $produits = $query->orderBy('nom')->get();

        // Le prix d'achat (la marge) ne regarde pas le caissier.
        if (! $request->user()->can('produits.update')) {
            $produits->each->makeHidden('prix_achat');
        }

        return response()->json($produits);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(Produit::create($data)->load('categorie'), 201);
    }

    public function update(Request $request, Produit $produit): JsonResponse
    {
        // Le stock ne se modifie PAS ici : toute variation passe par
        // `ajuster-stock`, qui la journalise. Un PUT qui changerait le stock
        // en silence rendrait l'écart de caisse et l'inventaire invérifiables.
        $data = collect($this->validated($request, sometimes: true, produit: $produit))->except('stock')->all();

        $produit->update($data);

        return response()->json($produit->load('categorie'));
    }

    public function ajusterStock(Request $request, Produit $produit, StockService $stocks): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $produit = $stocks->ajuster($produit, $data['stock'], $request->user(), $data['motif'] ?? null);

        return response()->json($produit->load('categorie'));
    }

    /** Photo de l'article, montrée dans la caisse. */
    public function photo(Request $request, Produit $produit, \App\Services\Images $images): JsonResponse
    {
        $request->validate(['photo' => \App\Services\Images::REGLES]);
        $produit->forceFill(['photo' => $images->enregistrer($request->file('photo'), 'produits', $produit->id, $produit->photo)])->save();

        return response()->json($produit->fresh()->load('categorie'));
    }

    public function supprimerPhoto(Produit $produit, \App\Services\Images $images): JsonResponse
    {
        $images->supprimer($produit->photo);
        $produit->forceFill(['photo' => null])->save();

        return response()->json($produit->fresh()->load('categorie'));
    }

    public function destroy(Produit $produit): JsonResponse
    {
        // Libère le code-barres : l'index d'unicité (boutique, code_barre)
        // compte aussi les lignes supprimées, et un article ressaisi avec le
        // même code après suppression échouerait sinon.
        $produit->update(['code_barre' => null]);
        $produit->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $sometimes = false, ?Produit $produit = null): array
    {
        $requis = $sometimes ? 'sometimes' : 'required';
        $boutiqueId = app(TenantContext::class)->boutiqueId();

        return $request->validate([
            // Filtrés par boutique : `exists` seul accepterait la catégorie
            // d'une autre boutique.
            'categorie_produit_id' => ['nullable', 'uuid', Rule::exists('categories_produits', 'id')->where('boutique_id', $boutiqueId)->whereNull('deleted_at')],
            'nom' => [$requis, 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:4'],
            // Déjà pris : on dit par quel article, pour que le commerçant le retrouve.
            'code_barre' => ['nullable', 'string', 'max:255', function (string $attribut, mixed $valeur, \Closure $echec) use ($boutiqueId, $produit): void {
                $existant = Produit::withoutGlobalScopes()->where('boutique_id', $boutiqueId)->where('code_barre', $valeur)
                    ->when($produit, fn ($q) => $q->whereKeyNot($produit->id))->first(['nom', 'format']);
                if ($existant) {
                    $echec('Ce code-barres est déjà celui de « '.trim($existant->nom.($existant->format ? ' – '.$existant->format : '')).' ».');
                }
            }],
            'prix_achat' => ['nullable', 'integer', 'min:0'],
            'prix_vente' => [$requis, 'integer', 'min:0'],
            'taux_tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'seuil_alerte' => ['nullable', 'integer', 'min:0'],
            'actif' => ['nullable', 'boolean'],
        ]);
    }
}
