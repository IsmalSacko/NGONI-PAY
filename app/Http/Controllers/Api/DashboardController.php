<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\Vente;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // Journée d'affaires en cours, comme le rapport et le ticket Z : après une
        // clôture, les ventes du soir comptent déjà pour le lendemain.
        $aujourdhui = Vente::valides()->where('jour_affaire', app(\App\Services\Journee::class)->courante()->toDateString());

        return response()->json([
            'ventes_jour' => [
                'nombre' => (clone $aujourdhui)->count(),
                'total' => (int) (clone $aujourdhui)->sum('total'),
            ],
            'moyens_paiement_jour' => (clone $aujourdhui)
                ->select('moyen_paiement', DB::raw('count(*) as nombre'), DB::raw('sum(total) as total'))
                ->groupBy('moyen_paiement')
                ->get(),
            'produits_en_rupture' => Produit::where('actif', true)->where('stock', '<=', 0)->count(),
            'produits_stock_faible' => Produit::where('actif', true)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'seuil_alerte')
                ->count(),
        ]);
    }
}
