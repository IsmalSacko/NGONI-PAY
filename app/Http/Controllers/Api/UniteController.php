<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\UniteBoutique;
use App\Support\Quantite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unités créées par la boutique, quand la liste de base ne suffit pas
 * (« tas », « boule », « mesure »…). Leur nom sert de code sur l'article.
 */
class UniteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => UniteBoutique::orderBy('nom')->get(['id', 'nom', 'pluriel'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->valider($request);
        $unite = UniteBoutique::create($data);
        Quantite::oublierPluriels();

        return response()->json(['data' => $unite->only(['id', 'nom', 'pluriel'])], 201);
    }

    /** Renommer : les articles suivent ; les tickets déjà faits gardent leur mot. */
    public function update(Request $request, UniteBoutique $unite): JsonResponse
    {
        $data = $this->valider($request, $unite);
        $ancien = $unite->nom;

        DB::transaction(function () use ($unite, $data, $ancien): void {
            $unite->update($data);
            if ($data['nom'] === $ancien) {
                return;
            }
            Produit::where('unite', $ancien)->update(['unite' => $data['nom']]);
            Produit::whereNotNull('paliers')->get()->each(function (Produit $p) use ($ancien, $data): void {
                $paliers = collect($p->paliers)->map(fn ($x) => $x['unite'] === $ancien ? [...$x, 'unite' => $data['nom']] : $x)->all();
                if ($paliers !== $p->paliers) {
                    $p->update(['paliers' => $paliers]);
                }
            });
        });
        Quantite::oublierPluriels();

        return response()->json(['data' => $unite->fresh()->only(['id', 'nom', 'pluriel'])]);
    }

    /** Une unité encore portée par un article ne se supprime pas. */
    public function destroy(UniteBoutique $unite): JsonResponse
    {
        $utilisee = Produit::where('unite', $unite->nom)->exists()
            || Produit::whereNotNull('paliers')->get(['paliers'])->contains(fn (Produit $p) => collect($p->paliers)->contains('unite', $unite->nom));
        if ($utilisee) {
            throw ValidationException::withMessages(['unite' => ['Des articles se vendent encore « au '.$unite->nom.' » : changez-les d’abord, ou renommez l’unité.']]);
        }
        $unite->delete();
        Quantite::oublierPluriels();

        return response()->json(null, 204);
    }

    /** @return array{nom: string, pluriel: ?string} */
    private function valider(Request $request, ?UniteBoutique $unite = null): array
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:20'],
            'pluriel' => ['nullable', 'string', 'max:24'],
        ]);

        return self::normaliser($data['nom'], $data['pluriel'] ?? null, $unite);
    }

    /**
     * Nom en minuscules, ni dans la liste de base ni en double ; pluriel
     * gardé seulement s'il est irrégulier. Partagé avec le back-office.
     *
     * @return array{nom: string, pluriel: ?string}
     */
    public static function normaliser(string $nom, ?string $pluriel, ?UniteBoutique $unite = null): array
    {
        $nom = mb_strtolower(trim($nom));
        $pluriel = mb_strtolower(trim((string) $pluriel));
        // Déjà dans la liste de base (« kg », « Kilo (kg) », « boîte »…) ou « pièce ».
        $deBase = collect(Quantite::UNITES)->keys()->merge(collect(Quantite::UNITES)->values())
            ->merge(collect(Quantite::LIBELLES)->values()->map(fn ($l) => mb_strtolower(trim((string) preg_replace('/\s*\(.*\)$/', '', $l)))))
            ->merge(['pièce', 'piece', 'pièces', 'unité', 'unite']);
        if ($nom === '' || $deBase->contains($nom)) {
            throw ValidationException::withMessages(['nom' => ['« '.$nom.' » est déjà dans la liste : choisissez-la.']]);
        }
        if (UniteBoutique::where('nom', $nom)->when($unite, fn ($q) => $q->whereKeyNot($unite->id))->exists()) {
            throw ValidationException::withMessages(['nom' => ['Vous avez déjà l’unité « '.$nom.' ».']]);
        }

        // Pluriel régulier : inutile de le garder.
        return ['nom' => $nom, 'pluriel' => $pluriel === '' || $pluriel === Quantite::pluriel($nom) ? null : $pluriel];
    }
}
