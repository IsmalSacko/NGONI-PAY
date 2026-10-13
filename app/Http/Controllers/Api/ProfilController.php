<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonCompte;
use App\Services\SuppressionCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

    /** Préférences du compte : pour l'instant, recevoir le bilan du soir ou non. */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['bilan_quotidien' => ['required', 'boolean']]);
        $request->user()->forceFill(['bilan_quotidien' => $data['bilan_quotidien']])->save();

        return response()->json(['message' => 'Préférences enregistrées.', 'user' => $request->user()->fresh()]);
    }

    public function motDePasse(Request $request, MonCompte $compte): JsonResponse
    {
        // Les autres appareils sont déconnectés ; celui-ci reste connecté.
        $compte->changerMotDePasse($request->user(), $request->all(), $request->user()->currentAccessToken()?->id);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }

    /**
     * Le titulaire supprime son compte depuis l'application : mot de passe et
     * « SUPPRIMER » tapés. Un propriétaire emporte ses boutiques et leur
     * équipe ; un employé, son seul compte. Une sauvegarde technique est
     * gardée 30 jours (voir SuppressionCompte).
     */
    public function supprimer(Request $request, SuppressionCompte $suppression): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'in:SUPPRIMER'],
        ], ['confirmation.in' => 'Tapez SUPPRIMER pour confirmer.']);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Mot de passe incorrect.']]);
        }
        $suppression->supprimer($request->user(), $request->user(), parLuiMeme: true);

        return response()->json(['message' => 'Votre compte et vos données ont été supprimés.']);
    }

    /** Ce que la suppression du compte emporterait (boutiques, données), pour le dire avant. */
    public function apercuSuppression(Request $request, SuppressionCompte $suppression): JsonResponse
    {
        return response()->json(['data' => $suppression->apercu($request->user())]);
    }
}
