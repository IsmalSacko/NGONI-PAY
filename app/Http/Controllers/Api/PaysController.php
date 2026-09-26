<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Pays proposés à l'inscription, à la connexion et au mot de passe oublié.
 *
 * Route publique : ces écrans en ont besoin avant toute session. Drapeau,
 * indicatif et devise viennent du serveur : un pays ajouté n'attend pas une
 * mise à jour de l'application.
 */
class PaysController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (Country $pays): array => [
                    'code' => $pays->value,
                    'nom' => $pays->label(),
                    'indicatif' => $pays->dialingCode(),
                    'drapeau' => $pays->flag(),
                    'devise' => $pays->currency(),
                ],
                Country::cases(),
            ),
            'defaut' => Country::default()->value,
        ]);
    }
}
