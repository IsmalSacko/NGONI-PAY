<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tutoriel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aide et tutoriels : la liste pour les commerçants, et sa gestion par
 * l'exploitant depuis la console.
 */
class TutorielController extends Controller
{
    /** Vidéos visibles, dans l'ordre choisi. */
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Tutoriel::CATEGORIES,
            'data' => Tutoriel::where('actif', true)->orderBy('ordre')->orderBy('id')->get()->map->versApplication(),
        ]);
    }

    /** Console : toutes, masquées comprises. */
    public function liste(): JsonResponse
    {
        return response()->json([
            'categories' => Tutoriel::CATEGORIES,
            'data' => Tutoriel::orderBy('ordre')->orderBy('id')->get()->map->versApplication(),
        ]);
    }

    public function enregistrer(Request $request): JsonResponse
    {
        $tutoriel = Tutoriel::create($this->valider($request) + ['ordre' => (int) Tutoriel::max('ordre') + 1]);

        return response()->json(['message' => "« {$tutoriel->titre} » ajouté.", 'data' => $tutoriel->versApplication()], 201);
    }

    public function modifier(Request $request, Tutoriel $tutoriel): JsonResponse
    {
        $tutoriel->update($this->valider($request, partiel: true));

        return response()->json(['message' => "« {$tutoriel->titre} » enregistré.", 'data' => $tutoriel->versApplication()]);
    }

    public function supprimer(Tutoriel $tutoriel): JsonResponse
    {
        $tutoriel->delete();

        return response()->json(['message' => "« {$tutoriel->titre} » supprimé."]);
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';

        return $request->validate([
            'titre' => [$requis, 'string', 'max:120'],
            'sous_titre' => ['nullable', 'string', 'max:160'],
            'categorie' => [$requis, Rule::in(array_keys(Tutoriel::CATEGORIES))],
            'url' => [$requis, 'url', 'max:255', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if (Tutoriel::idYoutube((string) $valeur) === null) {
                    $echec('Collez le lien d’une vidéo YouTube (youtube.com/watch?v=… ou youtu.be/…).');
                }
            }],
            'ordre' => ['sometimes', 'integer', 'min:0'],
            'actif' => ['sometimes', 'boolean'],
        ], [], ['url' => 'lien YouTube']);
    }
}
