<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Requests\Business\StoreBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessRequest;

class BusinessController extends Controller
{
    /**
     * Liste des businesses de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $businesses = Business::where('owner_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return BusinessResource::collection($businesses);
    }

    /**
     * Création d'un business
     */
    public function store(StoreBusinessRequest $request)
    {
        $business = Business::create([
            'owner_id' => $request->user()->id,
            'name' => $request->name,
            'type' => $request->type,
            'address' => $request->address,
            'phone' => $request->phone,
            'is_active' => true,
        ]);

        // Le owner est aussi un membre du staff
        $business->staff()->attach(
            $request->user()->id,
            ['role' => 'manager']
        );

        return new BusinessResource($business);
    }

    /**
     * Afficher un business
     */
    public function show(Business $business, Request $request)
    {
        $this->authorizeOwner($business, $request);

        return new BusinessResource($business);
    }

    /**
     * Mise à jour
     */
    public function update(UpdateBusinessRequest $request, Business $business)
    {
        $this->authorizeOwner($business, $request);

        $business->update($request->validated());

        return new BusinessResource($business);
    }

    /**
     * Désactivation logique
     */
    public function destroy(Business $business, Request $request)
    {
        $this->authorizeOwner($business, $request);

        $business->update(['is_active' => false]);

        return response()->json([
            'message' => 'Business désactivé'
        ]);
    }

    /**
     * Sécurité : seul le owner
     */
    private function authorizeOwner(Business $business, Request $request): void
    {
        if ($business->owner_id !== $request->user()->id) {
            abort(403, 'Accès interdit');
        }
    }
}
