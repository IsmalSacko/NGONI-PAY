<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Vente;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VenteController extends Controller
{
    public function __construct(private readonly VenteService $ventes) {}

    public function index(Request $request): JsonResponse
    {
        $query = Vente::with(['lignes', 'client', 'caissier'])->latest()->orderByDesc('numero');

        // Sans view_all (caissier) : ses propres ventes seulement.
        if (! $request->user()->can('ventes.view_all')) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('depuis')) {
            $query->where('created_at', '>=', $request->date('depuis'));
        }

        return response()->json($query->paginate(30));
    }

    public function show(Request $request, Vente $vente): JsonResponse
    {
        abort_unless($vente->user_id === $request->user()->id || $request->user()->can('ventes.view_all'), 404);

        return response()->json($vente->load(['lignes', 'client', 'caissier']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            // Un client de CETTE boutique : `exists` seul accepterait celui d'une autre.
            'client_id' => ['nullable', 'uuid', Rule::exists('clients', 'id')
                ->where('boutique_id', app(TenantContext::class)->boutiqueId())->whereNull('deleted_at')],
            'lignes' => ['required', 'array', 'min:1'],
            // Ligne du catalogue : produit_id (le prix est relu côté serveur).
            // Ligne libre : libellé + prix saisi, sans produit ni stock —
            // prestation, acompte, article hors catalogue.
            'lignes.*.produit_id' => ['nullable', 'uuid', 'required_without:lignes.*.libelle'],
            'lignes.*.libelle' => ['nullable', 'string', 'max:120', 'required_without:lignes.*.produit_id'],
            'lignes.*.prix_unitaire' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_with:lignes.*.libelle'],
            'lignes.*.taux_tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
