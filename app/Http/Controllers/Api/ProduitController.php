<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json($query->orderBy('nom')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(Produit::create($data), 201);
    }

    public function update(Request $request, Produit $produit): JsonResponse
    {
        $data = $this->validated($request, sometimes: true);

        $produit->update($data);

        return response()->json($produit);
    }

    public function destroy(Produit $produit): JsonResponse
    {
        $produit->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $sometimes = false): array
    {
        $requis = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'categorie_produit_id' => ['nullable', 'uuid', 'exists:categories_produits,id'],
            'nom' => [$requis, 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:4'],
            'code_barre' => ['nullable', 'string', 'max:255'],
            'prix_achat' => ['nullable', 'integer', 'min:0'],
            'prix_vente' => [$requis, 'integer', 'min:0'],
            'taux_tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'seuil_alerte' => ['nullable', 'integer', 'min:0'],
            'actif' => ['nullable', 'boolean'],
        ]);
    }
}
