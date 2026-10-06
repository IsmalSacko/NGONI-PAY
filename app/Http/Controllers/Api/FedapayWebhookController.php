<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaiementFedapay;
use App\Services\PaiementMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notification de FedaPay : signature vérifiée (X-FEDAPAY-SIGNATURE), puis la
 * transaction relue chez FedaPay avant toute activation. Répond 200 à un
 * appel authentique, même sans suite : FedaPay n'a pas à réessayer.
 */
class FedapayWebhookController extends Controller
{
    public function __invoke(Request $request, PaiementFedapay $fedapay, PaiementMobile $paiement): JsonResponse
    {
        if (! $fedapay->signatureValide($request->getContent(), $request->header('X-FEDAPAY-SIGNATURE'))) {
            Log::warning('FedaPay : webhook à la signature invalide', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $evenement = json_decode($request->getContent(), true);
        if (is_array($evenement) && str_starts_with((string) ($evenement['name'] ?? ''), 'transaction.')) {
            $demande = $fedapay->demandeDe($evenement);
            if ($demande !== null) {
                $paiement->verifier($demande);
            }
        }

        return response()->json(['message' => 'Reçu.']);
    }
}
