<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Mail\CodeReinitialisationMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly BoutiqueRegistrationService $registration) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom_boutique' => ['required', 'string', 'max:255'],
            'pays' => ['required', 'string', 'size:2'],
            'telephone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'nom_utilisateur' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->registration->register([
            'nom' => $data['nom_boutique'],
            'pays' => $data['pays'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'nom_utilisateur' => $data['nom_utilisateur'],
        ]);

        $token = $result['user']->createToken('e-caisse')->plainTextToken;

        return response()->json([
            'boutique' => $result['boutique'],
            'user' => $result['user'],
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
            'password' => ['required', 'string'],
        ]);

        $pays = Country::tryFrom(strtoupper($data['pays'] ?? '')) ?? Country::default();
        $candidats = PhoneNumber::candidates($data['telephone'], $pays);

        $user = User::withoutGlobalScopes()->whereIn('phone', $candidats)->first();

        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'telephone' => ['Identifiants incorrects.'],
            ]);
        }

        $token = $user->createToken('e-caisse')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $boutiqueId = app(\App\Support\Tenancy\TenantContext::class)->boutiqueId();

        // `boutique` : la boutique active de la requête (en-tête X-Boutique),
        // pas forcément celle par défaut du compte.
        $user->setRelation('boutique', \App\Models\Boutique::find($boutiqueId));

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * Mot de passe oublié, étape 1 : un code est envoyé à l'e-mail du compte.
     *
     * Même réponse que le numéro existe ou non : on ne laisse pas tester des
     * numéros au hasard. Sans e-mail, la réponse renvoie vers le support, qui
     * donne un mot de passe provisoire depuis la console.
     */
    public function motDePasseOublie(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
        ]);

        $user = $this->utilisateurParTelephone($data['telephone'], $data['pays'] ?? null);

        if ($user !== null && $user->is_active && filled($user->email)) {
            $code = (string) random_int(100000, 999999);

            PasswordResetCode::where('user_id', $user->id)->delete();
            PasswordResetCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(PasswordResetCode::VALIDITY_MINUTES),
            ]);

            try {
                Mail::to($user->email)->send(new CodeReinitialisationMail($code, $user->name));
            } catch (\Throwable $e) {
                Log::error('Code de réinitialisation non envoyé', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'Si ce numéro correspond à un compte avec une adresse e-mail, un code vient '
                .'de vous être envoyé. Sinon, contactez le support sur WhatsApp.',
            'code' => 'CODE_DEMANDE',
            'support_whatsapp' => config('ecaisse.support_whatsapp'),
        ]);
    }

    /**
     * Mot de passe oublié, étape 2 : nouveau mot de passe avec le code reçu.
     * Cinq essais par code ; toutes les sessions sont fermées ensuite.
     */
    public function reinitialiserMotDePasse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invalide = ValidationException::withMessages([
            'code' => ['Code invalide ou expiré. Demandez-en un nouveau.'],
        ]);

        $user = $this->utilisateurParTelephone($data['telephone'], $data['pays'] ?? null);

        if ($user === null || ! $user->is_active) {
            throw $invalide;
        }

        $demande = PasswordResetCode::where('user_id', $user->id)->latest('id')->first();

        if ($demande === null || $demande->expires_at->isPast() || $demande->attempts >= PasswordResetCode::MAX_ATTEMPTS) {
            throw $invalide;
        }

        if (! Hash::check($data['code'], $demande->code_hash)) {
            $demande->increment('attempts');

            throw $invalide;
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();
        PasswordResetCode::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé. Connectez-vous avec le nouveau.']);
    }

    private function utilisateurParTelephone(string $telephone, ?string $pays): ?User
    {
        $country = Country::tryFrom(strtoupper($pays ?? '')) ?? Country::default();

        return User::withoutGlobalScopes()->whereIn('phone', PhoneNumber::candidates($telephone, $country))->first();
    }
}
