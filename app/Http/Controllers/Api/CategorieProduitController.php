<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategorieProduit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategorieProduitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            CategorieProduit::orderBy('ordre')->orderBy('nom')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'couleur' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'ordre' => ['nullable', 'integer', 'min:0'],
        ]);
        // Sans couleur cochée : la première de la palette encore libre.
        $data['couleur'] ??= CategorieProduit::couleurLibre();

        return response()->json(CategorieProduit::create($data), 201);
    }

    public function update(Request $request, CategorieProduit $categorie): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'couleur' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'ordre' => ['nullable', 'integer', 'min:0'],
        ]);

        $categorie->update($data);

        return response()->json($categorie);
    }

    public function destroy(CategorieProduit $categorie): JsonResponse
    {
        $categorie->delete();

        return response()->json(status: 204);
    }
}
