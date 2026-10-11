<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\Produit;
use App\Services\Inventaires;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inventaire guidé : compter, voir les écarts en argent, valider. */
class InventaireController extends Controller
{
    public function __construct(private readonly Inventaires $inventaires) {}

    /** L'inventaire en cours (créé s'il n'y en a pas), ses comptages et ses écarts. */
    public function enCours(Request $request): JsonResponse
    {
        return response()->json($this->resume($this->inventaires->enCours($request->user())));
    }

    /** Les inventaires terminés : quand, et ce qu'il manquait. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Inventaire::where('statut', 'valide')->latest('valide_le')->limit(20)->get()
            ->map(fn (Inventaire $i) => [
                'id' => $i->id, 'valide_le' => $i->valide_le?->toIso8601String(),
                'valeur_manquante' => $i->valeur_manquante, 'valeur_surplus' => $i->valeur_surplus, 'articles_corriges' => $i->articles_corriges,
            ])]);
    }

    public function compter(Request $request, Inventaire $inventaire, Produit $produit): JsonResponse
    {
        $data = $request->validate(['quantite' => ['required', 'numeric', 'min:0', 'max:100000000']]);
        $this->inventaires->compter($inventaire, $produit, (float) $data['quantite'], $request->user());

        return response()->json($this->resume($inventaire));
    }

    public function retirer(Inventaire $inventaire, Produit $produit): JsonResponse
    {
        $this->inventaires->retirer($inventaire, $produit);

        return response()->json($this->resume($inventaire));
    }

    public function valider(Request $request, Inventaire $inventaire): JsonResponse
    {
        return response()->json($this->resume($this->inventaires->valider($inventaire, $request->user())));
    }

    public function abandonner(Inventaire $inventaire): JsonResponse
    {
        $this->inventaires->abandonner($inventaire);

        return response()->json(['message' => 'Inventaire abandonné : rien n’a changé.']);
    }

    /** @return array<string, mixed> */
    private function resume(Inventaire $inventaire): array
    {
        $lignes = $inventaire->lignes()->with(['produit', 'compteur:id,name'])->get();
        $valeurs = $lignes->map(fn (LigneInventaire $l) => Inventaires::valeur($l));

        return [
            'id' => $inventaire->id,
            'statut' => $inventaire->statut,
            'cree_le' => $inventaire->created_at?->toIso8601String(),
            'comptes' => $lignes->count(),
            // En direct pendant le comptage ; figés une fois validé.
            'valeur_manquante' => $inventaire->statut === 'valide' ? $inventaire->valeur_manquante : (int) -$valeurs->filter(fn ($v) => $v < 0)->sum(),
            'valeur_surplus' => $inventaire->statut === 'valide' ? $inventaire->valeur_surplus : (int) $valeurs->filter(fn ($v) => $v > 0)->sum(),
            'lignes' => $lignes->map(fn (LigneInventaire $l) => [
                'produit_id' => $l->produit_id,
                'quantite_comptee' => $l->quantite_comptee,
                'stock_au_comptage' => $l->stock_au_comptage,
                'ecart' => Inventaires::ecart($l),
                'valeur' => Inventaires::valeur($l),
                'compte_par' => $l->compteur?->name,
            ])->values(),
        ];
    }
}
