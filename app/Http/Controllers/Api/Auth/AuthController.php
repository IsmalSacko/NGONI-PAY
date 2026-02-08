<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;

use App\Models\User;
use App\Services\PhoneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{


    public function register(RegisterRequest $req, PhoneService $phoneService)
    {
        $phone = $phoneService->normalize($req->phone);
        $user = User::create([
            ...$req->validated(),
            'phone' => $phone,
            'password' => Hash::make($req->password),
            'role' => 'owner',

        ]);

        $token = $user->createToken('api')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $req, PhoneService $phoneService)
    {
        $phone = $phoneService->normalize($req->phone);
        $user = User::where('phone', $phone)->first();

        if (!$user || !Hash::check($req->password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
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
        if ($req->has('phone')) {
            $req->merge([
                'phone' => $phoneService->normalize($req->phone),
            ]);
        }
        $user = $req->user();
        $data = $req->only(['name', 'email', 'phone', 'avatar_url']);

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
}


