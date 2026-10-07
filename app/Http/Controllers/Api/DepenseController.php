<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Services\Depenses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Dépenses de la boutique et bilan (recettes, dépenses, résultat) — toutes les boutiques. */
class DepenseController extends Controller
{
    public function __construct(private readonly Depenses $depenses) {}

    /** Les dépenses d'un mois (par défaut le mois en cours), les plus récentes d'abord. */
    public function index(Request $request): JsonResponse
    {
        $mois = Carbon::parse($request->query('mois', now()->format('Y-m')).'-01');
        $liste = Depense::with('auteur')
            ->whereDate('jour', '>=', $mois->copy()->startOfMonth()->toDateString())
            ->whereDate('jour', '<=', $mois->copy()->endOfMonth()->toDateString())
            ->orderByDesc('jour')->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $liste->map(fn (Depense $d) => [
                'id' => $d->id, 'libelle' => $d->libelle, 'categorie' => $d->categorie, 'montant' => $d->montant,
                'moyen_paiement' => $d->moyen_paiement, 'jour' => $d->jour->toDateString(), 'par' => $d->auteur?->name,
            ])->values(),
            'total' => (int) $liste->sum('montant'),
            'categories' => Depense::CATEGORIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:120'],
            'categorie' => ['required', Rule::in(array_keys(Depense::CATEGORIES))],
            'montant' => ['required', 'integer', 'min:1'],
            'moyen_paiement' => ['nullable', Rule::enum(MoyenPaiement::class)],
            // Une dépense déjà payée : aujourd'hui ou avant, jamais à venir.
            'jour' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return response()->json(['data' => ['id' => $this->depenses->depenser($data, $request->user())->id]], 201);
    }

    public function destroy(Depense $depense): JsonResponse
    {
        $depense->delete();

        return response()->json(['ok' => true]);
    }

    /** Bilan d'une période : ?du=AAAA-MM-JJ&au=AAAA-MM-JJ (par défaut le mois en cours). */
    public function bilan(Request $request): JsonResponse
    {
        $data = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);
        $du = isset($data['du']) ? Carbon::parse($data['du']) : now()->startOfMonth();
        $au = isset($data['au']) ? Carbon::parse($data['au']) : now();

        return response()->json(['data' => $this->depenses->bilan($du, $au)]);
    }
}
