<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notifications du compte connecté (cloche de l'application). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requete = NotificationApp::where('user_id', $request->user()->id);

        return response()->json([
            'data' => (clone $requete)->latest('id')->limit(50)->get(['id', 'type', 'titre', 'message', 'lien', 'lue_le', 'created_at']),
            'non_lues' => (clone $requete)->whereNull('lue_le')->count(),
        ]);
    }

    public function lue(Request $request, int $notification): JsonResponse
    {
        NotificationApp::where('user_id', $request->user()->id)->whereKey($notification)->whereNull('lue_le')->update(['lue_le' => now()]);

        return response()->json(['message' => 'ok']);
    }

    /** Balayée dans l'application : supprimée (seulement les siennes). */
    public function supprimer(Request $request, int $notification): JsonResponse
    {
        NotificationApp::where('user_id', $request->user()->id)->whereKey($notification)->delete();

        return response()->json(['message' => 'ok']);
    }

    /**
     * Suppression en masse : les notifications cochées (`ids`), ou toutes
     * celles déjà lues (`lues`). Seulement les siennes, comme une par une.
     */
    public function supprimerPlusieurs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required_without:lues', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'lues' => ['sometimes', 'boolean'],
        ]);
        $requete = NotificationApp::where('user_id', $request->user()->id);
        $requete = ($data['lues'] ?? false) ? $requete->whereNotNull('lue_le') : $requete->whereKey($data['ids']);

        return response()->json(['supprimees' => $requete->delete()]);
    }

    public function toutLu(Request $request): JsonResponse
    {
        NotificationApp::where('user_id', $request->user()->id)->whereNull('lue_le')->update(['lue_le' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
