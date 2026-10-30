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

        // « Prévenir les commerçants » : coché par défaut ; le résumé part le lundi.
        $enAttente = $request->boolean('prevenir', true) && $annonces->mettreEnAttente($tutoriel);

        return response()->json([
            'message' => "« {$tutoriel->titre} » ajouté".($enAttente ? ' : les commerçants seront prévenus lundi (résumé des vidéos de la semaine).' : '.'),
            'data' => $tutoriel->versApplication(),
        ], 201);
    }

    public function modifier(Request $request, Tutoriel $tutoriel): JsonResponse
    {
        $tutoriel->update($this->valider($request, partiel: true));

        return response()->json(['message' => "« {$tutoriel->titre} » enregistré.", 'data' => $tutoriel->versApplication()]);
    }

    /** Prévenir les commerçants tout de suite, sans attendre le résumé du lundi. */
    public function annoncer(Request $request, Tutoriel $tutoriel, AnnonceTutoriel $annonces): JsonResponse
    {
        $resultat = $annonces->annoncerMaintenant($tutoriel, $request->user());
        if ($resultat === null) {
            return response()->json(['message' => 'Montrez d’abord la vidéo : masquée, elle n’est pas dans l’aide des commerçants.'], 422);
        }

        return response()->json([
            'message' => Tutoriel::messageAnnonce($tutoriel, $resultat['destinataires']),
            'destinataires' => $resultat['destinataires'],
            'data' => $tutoriel->fresh()->versApplication(),
        ]);
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
