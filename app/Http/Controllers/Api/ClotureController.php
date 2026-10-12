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
            // Clôturée par erreur aujourd'hui : elle se rouvre jusqu'à minuit.
            'peut_rouvrir' => $journee->rouvrable() !== null,
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

    public function rouvrir(Request $request, Journee $journee): JsonResponse
    {
        $jour = $journee->rouvrir($request->user());

        return response()->json(['message' => 'La journée du '.$jour->format('d/m/Y').' est rouverte : ses ventes s’annulent de nouveau, et les ventes faites depuis la clôture y sont revenues.']);
    }
}
