<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaiementMobile;
use App\Services\PaiementPawapay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appel de pawaPay à la fin d'un dépôt. Il n'est qu'un signal : le dépôt est
 * relu chez pawaPay (avec notre jeton) avant toute activation, un appel
 * inventé ne peut donc rien activer. Répond toujours 200 : pawaPay n'a pas à
 * réessayer.
 */
class PawapayWebhookController extends Controller
{
    public function __invoke(Request $request, PaiementPawapay $pawapay, PaiementMobile $paiement): JsonResponse
    {
        $appel = json_decode($request->getContent(), true);
        $demande = is_array($appel) ? $pawapay->demandeDe($appel) : null;
        if ($demande !== null) {
            $paiement->verifier($demande);
        }

        return response()->json(['message' => 'Reçu.']);
    }
}
