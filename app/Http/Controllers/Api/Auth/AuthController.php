<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;

use App\Models\User;
use App\Models\PasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Services\PhoneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class AuthController extends Controller
{


    /**
     * Le numéro et le pays ont été mis en forme par la requête : ils arrivent
     * ici sous la forme qui sera enregistrée, et qui servira à se reconnecter.
     */
    public function register(RegisterRequest $req)
    {
        $user = User::create([
            ...$req->validated(),
            'password' => Hash::make($req->password),
            'role' => 'owner',

        ]);

        $token = $user->createToken('api')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Le compte est cherché sous toutes les écritures possibles du numéro saisi.
     *
     * Un même abonné a pu être enregistré « +22376008201 » aujourd'hui et
     * « 76008201 » avant que le pays ne soit demandé : une seule requête ne
     * retrouverait qu'une partie des comptes.
     */
    public function login(LoginRequest $req)
    {
        $user = User::whereIn('phone', $req->phoneCandidates())->first();

        if (!$user || !Hash::check($req->password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        // Compte désactivé : la colonne existait et le panneau permettait de la
        // basculer, mais rien ne la lisait — désactiver un compte n'avait aucun
        // effet, son propriétaire continuait de se connecter et d'encaisser.
        //
        // Le refus est explicite et dit quoi faire : un « identifiants
        // invalides » enverrait chercher un mot de passe perdu.
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Votre compte a été désactivé. Contactez le service '
                    . 'client pour le réactiver.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 200);
    }

    public function me(Request $req)
    {
        return new UserResource($req->user());
    }

    public function updateProfile(Request $req, PhoneService $phoneService)
    {
        $user = $req->user();

        // Le pays peut changer — un commerçant qui déménage, ou une inscription
        // faite avec la présélection sans y regarder. Il est retenu avant le
        // numéro, puisque c'est lui qui donne l'indicatif à appliquer.
        $country = $user->countryEnum();

        if ($req->filled('country')) {
            $req->validate([
                'country' => [Rule::enum(Country::class)],
            ]);

            $country = Country::from(strtoupper((string) $req->input('country')));
            $req->merge(['country' => $country->value]);
        }

        if ($req->has('phone')) {
            $req->merge([
                'phone' => $phoneService->normalize($req->phone, $country),
            ]);
        }

        $data = $req->only(['name', 'email', 'phone', 'country', 'avatar_url']);

        if ($req->boolean('remove_avatar')) {
            if ($user->avatar_url) {
                $path = parse_url($user->avatar_url, PHP_URL_PATH);
                if ($path) {
                    $relative = str_replace('/storage/', '', $path);
                    if ($relative) {
                        Storage::disk('public')->delete($relative);
                    }
                }
            }
            $data['avatar_url'] = null;
        }

        if ($req->hasFile('avatar')) {
            $req->validate([
                'avatar' => 'image|max:2048',
            ]);

            $path = $req->file('avatar')->storePublicly('avatars', 'public');
            $data['avatar_url'] = url(Storage::url($path));
        }
        // If no file is sent, allow base64 or a direct URL string from the client
        if (!$req->hasFile('avatar') && $req->filled('avatar_base64')) {
            $req->validate([
                'avatar_base64' => 'string',
            ]);

            $input = $req->input('avatar_base64');
            $ext = 'jpg';
            $dataPart = $input;

            if (preg_match('/^data:image\\/(\\w+);base64,/', $input, $matches)) {
                $ext = strtolower($matches[1]);
                $dataPart = substr($input, strpos($input, ',') + 1);
            }

            $decoded = base64_decode($dataPart, true);
            if ($decoded === false) {
                return response()->json([
                    'message' => 'avatar_base64 invalide.',
                ], 422);
            }

            $filename = 'avatars/' . Str::uuid() . '.' . $ext;
            Storage::disk('public')->put($filename, $decoded);
            $data['avatar_url'] = url(Storage::url($filename));
        }

        if (!$req->hasFile('avatar') && !$req->filled('avatar_base64') && $req->filled('avatar_url')) {
            $req->validate([
                'avatar_url' => 'url',
            ]);
            $data['avatar_url'] = $req->input('avatar_url');
        }

        $user->update($data);

        return new UserResource($user);
    }
    public function changePassword(Request $req)
    {
        $req->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = $req->user();
        if (!Hash::check($req->current_password, $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }

        $user->password = Hash::make($req->new_password);
        $user->save();

        return response()->json(['message' => 'Mot de passe mis à jour.'], 200);
    }

    /**
     * Étape 1 : demander un code de réinitialisation.
     *
     * L'ancienne version changeait le mot de passe sur simple numéro de
     * téléphone : n'importe qui pouvait prendre un compte. Désormais, un code
     * est envoyé à l'adresse e-mail du compte. Sans e-mail, la réinitialisation
     * passe par le support (mot de passe provisoire donné depuis la console).
     *
     * La réponse est la même que le compte existe ou non, pour ne pas laisser
     * tester des numéros au hasard.
     */
    public function forgotPassword(Request $req, PhoneService $phoneService)
    {
        // Application antérieure : elle envoie directement le nouveau mot de passe.
        if ($req->filled('new_password') && ! $req->filled('code')) {
            return response()->json([
                'message' => 'Mettez à jour l’application pour réinitialiser votre mot de passe, '
                    . 'ou contactez le support sur WhatsApp au ' . config('subscriptions.support_whatsapp') . '.',
                'code' => 'RESET_REQUIRES_CODE',
                'support_whatsapp' => config('subscriptions.support_whatsapp'),
            ], 422);
        }

        $req->validate([
            'phone' => 'required|string',
            'country' => ['sometimes', 'nullable', Rule::enum(Country::class)],
        ]);

        $user = $this->userByPhone($req, $phoneService);

        // Réinitialiser le mot de passe d'un compte désactivé n'y donnerait pas
        // accès, et laisserait croire le contraire.
        if ($user && ! $user->is_active) {
            return $this->deactivatedResponse();
        }

        if ($user && filled($user->email)) {
            $code = (string) random_int(100000, 999999);

            PasswordResetCode::where('user_id', $user->id)->delete();
            PasswordResetCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(15),
            ]);

            try {
                Mail::to($user->email)->send(new PasswordResetCodeMail($code, $user->name));
            } catch (\Throwable $e) {
                Log::error('Code de réinitialisation non envoyé', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'Si ce numéro correspond à un compte avec une adresse e-mail, un code vient '
                . 'de vous être envoyé par e-mail. Sinon, contactez le support sur WhatsApp pour '
                . 'réinitialiser votre mot de passe.',
            'code' => 'RESET_CODE_REQUESTED',
            'support_whatsapp' => config('subscriptions.support_whatsapp'),
        ]);
    }

    /**
     * Étape 2 : choisir un nouveau mot de passe avec le code reçu par e-mail.
     * Cinq essais par code ; toutes les sessions ouvertes sont fermées ensuite.
     */
    public function resetPassword(Request $req, PhoneService $phoneService)
    {
        $req->validate([
            'phone' => 'required|string',
            'country' => ['sometimes', 'nullable', Rule::enum(Country::class)],
            'code' => 'required|digits:6',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $invalide = response()->json([
            'message' => 'Code invalide ou expiré. Demandez-en un nouveau.',
            'code' => 'RESET_CODE_INVALID',
        ], 422);

        $user = $this->userByPhone($req, $phoneService);
        if (! $user) {
            return $invalide;
        }
        if (! $user->is_active) {
            return $this->deactivatedResponse();
        }

        $demande = PasswordResetCode::where('user_id', $user->id)->latest('id')->first();

        if (! $demande || $demande->expires_at->isPast() || $demande->attempts >= PasswordResetCode::MAX_ATTEMPTS) {
            return $invalide;
        }

        if (! Hash::check((string) $req->code, $demande->code_hash)) {
            $demande->increment('attempts');

            return $invalide;
        }

        $user->password = Hash::make($req->new_password);
        $user->save();
        $user->tokens()->delete();
        PasswordResetCode::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé. Connectez-vous avec le nouveau.'], 200);
    }

    /**
     * Même tolérance qu'à la connexion : le numéro saisi peut ne pas être
     * écrit comme il a été enregistré.
     */
    private function userByPhone(Request $req, PhoneService $phoneService): ?User
    {
        $country = Country::tryFrom(strtoupper((string) $req->input('country')))
            ?? Country::default();

        return User::whereIn('phone', $phoneService->candidates((string) $req->phone, $country))->first();
    }

    private function deactivatedResponse()
    {
        return response()->json([
            'message' => 'Votre compte a été désactivé. Contactez le service '
                . 'client pour le réactiver.',
            'code' => 'ACCOUNT_DEACTIVATED',
        ], 403);
    }

    public function destroy(Request $req)
    {
        $user = $req->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Compte supprimé.'], 200);
    }

    public function logout(Request $req)
    {
        //$req->user()->currentAccessToken()->delete();
        $req->user()->tokens()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.'], 200);
    }

    public function users(Request $request)
    {
        $viewer = $request->user();
        if (!$this->isPrivilegedViewer($viewer)) {
            return response()->json(['message' => 'Accès interdit'], 403);
        }

        $users = User::query()
            ->select(['id', 'name', 'phone', 'country', 'email', 'role', 'avatar_url', 'created_at'])
            ->orderByDesc('created_at')
            ->get();

        return UserResource::collection($users);
    }

    public function deleteUser(Request $request, User $user)
    {
        $viewer = $request->user();
        if (!$this->isPrivilegedViewer($viewer)) {
            return response()->json(['message' => 'Accès interdit'], 403);
        }

        if ((int) $viewer->id === (int) $user->id) {
            return response()->json([
                'message' => 'Utilisez /auth/delete pour supprimer votre propre compte.'
            ], 422);
        }

        if ($this->isPrivilegedViewer($user)) {
            return response()->json([
                'message' => 'Impossible de supprimer ce compte protégé.'
            ], 403);
        }

        try {
            $user->tokens()->delete();
            $user->delete();
        } catch (\Throwable $e) {
            $message = 'Suppression impossible pour ce compte.';
            if (config('app.debug')) {
                $message .= ' ' . $e->getMessage();
            }

            return response()->json([
                'message' => $message,
            ], 422);
        }

        return response()->json([
            'message' => 'Utilisateur supprimé avec succès.'
        ], 200);
    }

    private function isPrivilegedViewer(User $user): bool
    {
        // Accès réservé aux super-administrateurs (rôle system_admin).
        return $user->isSystemAdmin();
    }
}
