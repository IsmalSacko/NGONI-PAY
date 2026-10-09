<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tutoriel;
use App\Services\AnnonceTutoriel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function enregistrer(Request $request, AnnonceTutoriel $annonces): JsonResponse
    {
        $tutoriel = Tutoriel::create($this->valider($request) + ['ordre' => (int) Tutoriel::max('ordre') + 1]);

        // « Prévenir les commerçants » : coché par défaut.
        $annonce = $request->boolean('prevenir', true) ? $annonces->annoncer($tutoriel, $request->user()) : null;

        return response()->json([
            'message' => "« {$tutoriel->titre} » ajouté".($annonce ? ' : les commerçants sont prévenus.' : '.'),
            'data' => $tutoriel->versApplication(),
        ], 201);
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
        return $request->validate(Tutoriel::regles($partiel), [], ['url' => 'lien YouTube']);
    }
}
