<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Rapports;
use App\Services\Statistiques;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Statistiques de la boutique active, pour l'écran « Pilotage » : le rapport
 * de la période, sa comparaison avec la précédente, et — si le plan
 * l'inclut — l'analyse avancée. Sans elle, `analyse` est null et
 * `analyse_disponible` false : l'application montre ce que le Pro apporterait.
 */
class StatistiqueController extends Controller
{
    /** Au-delà, la comparaison et la grille d'affluence n'ont plus grand sens, et coûtent. */
    private const JOURS_MAX = 366;

    public function __invoke(Request $request, Rapports $rapports, Statistiques $statistiques, AbonnementService $abonnements, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]);
        $au = isset($data['au']) ? Carbon::parse($data['au']) : today();
        $du = isset($data['du']) ? Carbon::parse($data['du']) : $au->copy()->subDays(6);
        abort_if($du->diffInDays($au) > self::JOURS_MAX, 422, 'Période trop longue : un an au plus.');

        $boutique = Boutique::findOrFail($tenant->boutiqueId());
        $disponible = $abonnements->permet($boutique, Plan::STATISTIQUES_AVANCEES);

        return response()->json([
            'rapport' => $rapports->periode($du, $au),
            'comparaison' => $statistiques->comparaison($du, $au),
            'analyse_disponible' => $disponible,
            'analyse' => $disponible ? $statistiques->analyse($boutique, $du, $au) : null,
        ]);
    }
}
