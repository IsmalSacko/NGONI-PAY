<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LigneVente;
use App\Models\Lot;
use App\Models\Produit;
use App\Services\Images;
use App\Services\Lots;
use App\Services\StockService;
use App\Support\Quantite;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProduitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Pharmacie : la date du lot qui périme le plus tôt, pour l'alerte en caisse.
        $query = Produit::with('categorie')->where('actif', true)
            ->withMin(['lots as prochaine_peremption' => fn ($q) => $q->where('quantite', '>', 0)], 'peremption');

        if ($request->filled('categorie_produit_id')) {
            $query->where('categorie_produit_id', $request->string('categorie_produit_id'));
        }

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'like', $terme)->orWhere('code_barre', 'like', $terme)->orWhere('dci', 'like', $terme);
            });
        }

        $produits = $query->orderBy('nom')->get();

        // Le prix d'achat (la marge) ne regarde pas le caissier.
        if (! $request->user()->can('produits.update')) {
            $produits->each->makeHidden('prix_achat');
        }

        return response()->json($produits);
    }

    /** Lots entamés qui périment dans les 90 jours, ou déjà périmés : le plus proche d'abord. */
    public function peremption(): JsonResponse
    {
        $limite = now()->addDays(90)->toDateString();
        $lots = Lot::with('produit:id,nom,unite,paliers,prix_achat,prix_vente')
            ->where('quantite', '>', 0)->whereNotNull('peremption')->where('peremption', '<=', $limite)
            ->whereHas('produit', fn ($q) => $q->where('actif', true))
            ->orderBy('peremption')->get()
            ->map(fn (Lot $l) => [
                'lot_id' => $l->id,
                'produit_id' => $l->produit_id,
                'nom' => $l->produit->nom,
                'unite' => $l->produit->unite,
                'paliers' => $l->produit->paliers,
                'numero' => $l->numero,
                'peremption' => $l->peremption->toDateString(),
                'jours' => (int) now()->startOfDay()->diffInDays($l->peremption, false),
                'quantite' => $l->quantite,
                // Ce que le lot a coûté : ce qu'on perd s'il périme.
                'valeur' => (int) round($l->quantite * ($l->produit->prix_achat ?? $l->produit->prix_vente)),
            ]);

        return response()->json(['data' => $lots]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $lot = $request->validate([
            'numero_lot' => ['nullable', 'string', 'max:60'],
            'peremption' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $produit = DB::transaction(function () use ($data, $lot): Produit {
            $produit = Produit::create($data);
            // Pharmacie : le stock de départ forme le premier lot.
            app(Lots::class)->entrer($produit, $produit->stock ?? 0, $lot['numero_lot'] ?? null, $lot['peremption'] ?? null);

            return $produit;
        });

        return response()->json($produit->load('categorie'), 201);
    }

    public function update(Request $request, Produit $produit): JsonResponse
    {
        // Le stock ne se modifie PAS ici : toute variation passe par
        // `ajuster-stock`, qui la journalise. Un PUT qui changerait le stock
        // en silence rendrait l'écart de caisse et l'inventaire invérifiables.
        $data = collect($this->validated($request, sometimes: true, produit: $produit))->except('stock')->all();

        // Figé dès la première vente : sinon l'historique (« 3 » vendus :
        // 3 pièces ou 3 kg ?) deviendrait faux.
        if (array_key_exists('unite', $data) && $data['unite'] !== $produit->unite && LigneVente::where('produit_id', $produit->id)->exists()) {
            throw ValidationException::withMessages(['unite' => ['Cet article a déjà été vendu : sa façon de se vendre ne change plus. Créez un nouvel article.']]);
        }

        $produit->update($data);

        return response()->json($produit->load('categorie'));
    }

    public function ajusterStock(Request $request, Produit $produit, StockService $stocks): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'numeric', 'min:0', 'max:100000000', 'decimal:0,3'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $produit = $stocks->ajuster($produit, $data['stock'], $request->user(), $data['motif'] ?? null);

        return response()->json($produit->load('categorie'));
    }

    /** Photo de l'article, montrée dans la caisse. */
    public function photo(Request $request, Produit $produit, Images $images): JsonResponse
    {
        $request->validate(['photo' => Images::REGLES]);
        $produit->forceFill(['photo' => $images->enregistrer($request->file('photo'), 'produits', $produit->id, $produit->photo)])->save();

        return response()->json($produit->fresh()->load('categorie'));
    }

    public function supprimerPhoto(Produit $produit, Images $images): JsonResponse
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

        $data = $request->validate([
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
            'stock' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'decimal:0,3'],
            'seuil_alerte' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'decimal:0,3'],
            // Vendu à : vide = à la pièce. Voir update() : figé après la première vente.
            'unite' => ['nullable', 'string', 'max:40', Quantite::regle()],
            // Pharmacie : la molécule, l'ordonnance, et la vente au détail —
            // paliers du plus petit au plus grand (plaquette de 8, boîte de 16),
            // chacun à son prix ; l'article lui-même est l'unité de base.
            'dci' => ['nullable', 'string', 'max:120'],
            'sur_ordonnance' => ['nullable', 'boolean'],
            'paliers' => ['nullable', 'array', 'max:3'],
            'paliers.*.unite' => ['required', 'string', 'max:40', 'distinct', Quantite::regle()],
            'paliers.*.contenance' => ['required', 'integer', 'min:2', 'max:100000'],
            'paliers.*.prix' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'actif' => ['nullable', 'boolean'],
        ]);

        // Du plus petit au plus grand ; aucun palier : pas de détail.
        if (array_key_exists('paliers', $data)) {
            $paliers = collect($data['paliers'] ?? [])
                ->map(fn ($p) => ['unite' => $p['unite'], 'contenance' => (int) $p['contenance'], 'prix' => (int) $p['prix']])
                ->sortBy('contenance')->values()->all();
            $data['paliers'] = $paliers === [] ? null : $paliers;
        }

        return $data;
    }
}
