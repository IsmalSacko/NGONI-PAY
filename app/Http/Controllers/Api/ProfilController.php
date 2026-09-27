<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Son propre compte, pour tout membre de la boutique (caissier compris) : nom,
 * téléphone, e-mail et mot de passe. Jamais le rôle : il reste l'affaire du
 * titulaire de la boutique (EquipeController).
 */
class ProfilController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        // Le numéro se lit dans le pays de la boutique, comme à l'inscription.
        $pays = Country::tryFrom((string) Boutique::find(app(TenantContext::class)->boutiqueId())?->pays) ?? Country::default();

        $user = $request->user();
        $user->update([
            'name' => trim($data['name']),
            'phone' => PhoneNumber::normalize($data['telephone'], $pays),
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
        ]);

        return response()->json(['message' => 'Profil mis à jour.', 'user' => $user->fresh()]);
    }

    public function motDePasse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'mot_de_passe.min' => 'Au moins 8 caractères.',
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $user = $request->user();
        if (! Hash::check($data['mot_de_passe_actuel'], $user->password)) {
            throw ValidationException::withMessages(['mot_de_passe_actuel' => ['Mot de passe actuel incorrect.']]);
        }

        $user->forceFill(['password' => $data['mot_de_passe']])->save();

        // Les autres appareils sont déconnectés ; celui-ci reste connecté.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Mot de passe modifié.']);
    }
}
