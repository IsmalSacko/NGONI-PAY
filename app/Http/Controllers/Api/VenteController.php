<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Vente;
use App\Services\VenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VenteController extends Controller
{
    public function __construct(private readonly VenteService $ventes) {}

    public function index(Request $request): JsonResponse
    {
        $query = Vente::with(['lignes', 'client', 'caissier'])->latest();

        if ($request->filled('depuis')) {
            $query->where('created_at', '>=', $request->date('depuis'));
        }

        return response()->json($query->paginate(30));
    }

    public function show(Vente $vente): JsonResponse
    {
        return response()->json($vente->load(['lignes', 'client', 'caissier']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'remise' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
            'montant_recu' => ['nullable', 'integer', 'min:0'],
            'vendue_hors_ligne' => ['nullable', 'boolean'],
        ]);

        $vente = $this->ventes->encaisser($data, $request->user());

        return response()->json($vente, 201);
    }
}
