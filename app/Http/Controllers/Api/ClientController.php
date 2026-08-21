<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Business;
use App\Enums\Country;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;

class ClientController extends Controller
{
    /**
     * Liste des clients d’un business
     */
    public function index(Business $business, Request $request)
    {
        $this->authorizeAccess($business, $request);

        $clients = $business->clients()
            ->latest()
            ->paginate(15);

        return ClientResource::collection($clients);
    }

    /**
     * Créer un client
     */
    public function store(StoreClientRequest $request, Business $business)
    {
        $this->authorizeManager($business, $request);

        $client = Client::create([
            'business_id' => $business->id,
            'name'  => $request->name,
            'phone' => $this->normalizePhone($request->phone, $business),
            'email' => $request->email,
            'notes' => $request->notes,
        ]);

        return new ClientResource($client);
    }

    /**
     * Voir un client
     */
    public function show(Business $business, Client $client, Request $request)
    {
        $this->authorizeAccess($business, $request);
        $this->ensureSameBusiness($business, $client);

        return new ClientResource($client);
    }

    public function findByPhone(Request $request, Business $business)
    {
        $phone = $request->query('phone');

        if (! $phone) {
            return response()->json(['data' => null]);
        }

        // La recherche porte sur la forme enregistrée, pas sur la saisie : le
        // caissier tape « 76008201 » quand la fiche porte « +22376008201 ».
        $client = Client::where('business_id', $business->id)
            ->where('phone', $this->normalizePhone((string) $phone, $business))
            ->first();

        return response()->json([
            'data' => $client,
        ]);
    }

    /**
     * Mettre à jour
     */
    public function update(
        UpdateClientRequest $request,
        Business $business,
        Client $client
    ) {
        $this->authorizeManager($business, $request);
        $this->ensureSameBusiness($business, $client);

        $data = $request->validated();

        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone'], $business);
        }

        $client->update($data);

        return new ClientResource($client);
    }

    /**
     * Supprimer
     */
    public function destroy(Business $business, Client $client, Request $request)
    {
        $this->authorizeManager($business, $request);
        $this->ensureSameBusiness($business, $client);

        $client->delete();

        return response()->json([
            'message' => 'Client supprimé'
        ]);
    }

    /* ================== SÉCURITÉ ================== */

    private function authorizeAccess(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) {
            return;
        }

        $isStaff = $business->staff()
            ->where('user_id', $user->id)
            ->exists();

        if (! $isStaff) {
            abort(403, 'Accès interdit');
        }
    }

    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) {
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

    private function ensureSameBusiness(Business $business, Client $client): void
    {
        if ($client->business_id !== $business->id) {
            abort(404);
        }
    }

    /**
     * Numéro du client, sous la forme qui sert de clé dans son business.
     *
     * L'indicatif est celui du pays du propriétaire : c'est là que le commerce
     * encaisse, donc là que ses clients ont leur numéro.
     */
    private function normalizePhone(string $phone, Business $business): string
    {
        return PhoneNumber::normalize(
            $phone,
            $business->owner?->countryEnum() ?? Country::default(),
        );
    }

}
