<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appareil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** L'application déclare son jeton Firebase après la connexion. */
class AppareilController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jeton' => ['required', 'string', 'max:512'],
            'plateforme' => ['nullable', 'string', 'in:android,ios'],
        ]);

        // Un appareil appartient au dernier compte qui s'y est connecté.
        Appareil::updateOrCreate(
            ['jeton' => $data['jeton']],
            ['user_id' => $request->user()->id, 'plateforme' => $data['plateforme'] ?? 'android'],
        );

        return response()->json(['message' => 'ok']);
    }
}
