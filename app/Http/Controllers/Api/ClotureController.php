<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cloture;
use App\Models\Vente;
use App\Services\Journee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Journée en cours et clôtures (tickets Z) de la boutique active. */
class ClotureController extends Controller
{
    /** Journée en cours : sa date, le nombre de tickets et la dernière clôture. */
    public function journee(Journee $journee): JsonResponse
    {
        $jour = $journee->courante();

        return response()->json([
            'jour_affaire' => $jour->toDateString(),
            'tickets' => (int) Vente::whereDate('jour_affaire', $jour)->max('numero_jour'),
            'derniere_cloture' => Cloture::with('auteur:id,name')->latest('numero')->first(),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(Cloture::with('auteur:id,name')->latest('numero')->paginate(30));
    }

    public function store(Request $request, Journee $journee): JsonResponse
    {
        return response()->json($journee->cloturer($request->user())->load('auteur:id,name'), 201);
    }
}
