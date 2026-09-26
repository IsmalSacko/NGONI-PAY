<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rapports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rapport d'une période (gérant, admin) : clôture du jour, semaine, mois. */
class RapportController extends Controller
{
    public function __invoke(Request $request, Rapports $rapports): JsonResponse
    {
        $data = $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]);

        $du = isset($data['du']) ? Carbon::parse($data['du']) : today();
        $au = isset($data['au']) ? Carbon::parse($data['au']) : $du->copy();

        if ($du->diffInDays($au) > 366) {
            return response()->json(['message' => 'Une période d’un an au plus.'], 422);
        }

        return response()->json($rapports->periode($du, $au));
    }
}
