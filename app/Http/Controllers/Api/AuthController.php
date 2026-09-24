<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        $user = $request->user()->load('boutique');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
