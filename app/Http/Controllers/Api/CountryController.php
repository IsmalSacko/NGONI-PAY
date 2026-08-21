<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Pays proposés à l'inscription et à la connexion.
 *
 * Route publique : les deux écrans en ont besoin avant toute session. Le
 * drapeau, l'indicatif et la devise viennent du serveur pour qu'un pays ajouté
 * n'attende pas une mise à jour de l'application sur les téléphones.
 */
class CountryController extends Controller
{
    public function index(): JsonResponse
    {
        $countries = array_map(
            fn (Country $country): array => [
                'code' => $country->value,
                'name' => $country->label(),
                'dialingCode' => $country->dialingCode(),
                'flag' => $country->flag(),
                'currency' => $country->currency(),
            ],
            Country::cases(),
        );

        return response()->json([
            'data' => $countries,
            // Présélection des écrans d'inscription et de connexion.
            'default' => Country::default()->value,
        ]);
    }
}
