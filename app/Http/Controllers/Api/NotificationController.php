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

    public function toutLu(Request $request): JsonResponse
    {
        NotificationApp::where('user_id', $request->user()->id)->whereNull('lue_le')->update(['lue_le' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
