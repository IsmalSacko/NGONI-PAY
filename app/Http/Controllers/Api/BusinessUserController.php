<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessUserResource;
use App\Http\Requests\BusinessUser\StoreBusinessUserRequest;
use App\Http\Requests\BusinessUser\UpdateBusinessUserRequest;

class BusinessUserController extends Controller
{
    /**
     * Liste du staff d’un business
     */
    public function index(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        return BusinessUserResource::collection(
            $business->staff()->get()
        );
    }

    /**
     * Ajouter un membre
     */
    public function store(StoreBusinessUserRequest $request, Business $business)
    {
        $this->authorizeManager($business, $request);
        // Vérifier si l'utilisateur est déjà membre
        if ($business->staff()->where('user_id', $request->user_id)->exists()) {
            return response()->json([
                'message' => 'Utilisateur déjà membre'
            ], 422);
        }

        $business->staff()->attach(
            $request->user_id,
            ['role' => $request->role]
        );

        return response()->json([
            'message' => 'Membre ajouté'
        ], 201);
    }

    /**
     * Modifier le rôle
     */
    public function update(
        UpdateBusinessUserRequest $request,
        Business $business,
        User $user
    ) {
        $this->authorizeManager($business, $request);

        if ($business->owner_id === $user->id) {
            return response()->json([
                'message' => 'Impossible de modifier le propriétaire'
            ], 403);
        }

        $business->staff()->updateExistingPivot(
            $user->id,
            ['role' => $request->role]
        );

        return response()->json([
            'message' => 'Rôle mis à jour'
        ]);
    }

    /**
     * Retirer un membre
     */
    public function destroy(Business $business, User $user, Request $request)
    {
        $this->authorizeManager($business, $request);

        if ($business->owner_id === $user->id) {
            return response()->json([
                'message' => 'Impossible de retirer le propriétaire'
            ], 403);
        }

        $business->staff()->detach($user->id);

        return response()->json([
            'message' => 'Membre retiré'
        ]);
    }

    /**
     * Autorisation : owner ou manager
     */
    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($business->owner_id === $user->id) {
            return;
        }

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) {
            abort(403, 'Accès interdit');
        }
    }
}
