<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Son propre compte, pour tout membre de la boutique (caissier compris) : nom,
 * téléphone, e-mail et mot de passe (voir MonCompte, partagé avec le web).
 */
class ProfilController extends Controller
{
    public function update(Request $request, MonCompte $compte): JsonResponse
    {
        return response()->json(['message' => 'Profil mis à jour.', 'user' => $compte->modifierProfil($request->user(), $request->all())]);
    }

    public function motDePasse(Request $request, MonCompte $compte): JsonResponse
    {
        // Les autres appareils sont déconnectés ; celui-ci reste connecté.
        $compte->changerMotDePasse($request->user(), $request->all(), $request->user()->currentAccessToken()?->id);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }
}
