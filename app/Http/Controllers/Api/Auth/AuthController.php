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
        $data = $req->only(['name', 'email', 'phone']);

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

        $user->update($data);

        return new UserResource($user);
    }

    public function logout(Request $req)
    {
        //$req->user()->currentAccessToken()->delete();
        $req->user()->tokens()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.'], 200);
    }
}
