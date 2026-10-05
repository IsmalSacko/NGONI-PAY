<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaiementJeko;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notification de Jèko : signature vérifiée sur le corps brut, puis le
 * paiement relu chez Jèko avant toute activation. Répond vite et toujours
 * 200 à un appel authentique, même sans suite : Jèko n'a pas à réessayer.
 */
class JekoWebhookController extends Controller
{
    public function __invoke(Request $request, PaiementJeko $jeko): JsonResponse
    {
        if (! $jeko->signatureValide($request->getContent(), $request->header('Jeko-Signature'))) {
            Log::warning('Jèko : webhook à la signature invalide', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $evenement = (string) $request->header('Jeko-Event');
        $corps = json_decode($request->getContent(), true);

        if ($evenement === 'TRANSACTION_COMPLETED' && is_array($corps) && ($corps['transactionType'] ?? null) === 'payment') {
            $jeko->recevoir($corps);
        }

        return response()->json(['message' => 'Reçu.']);
    }
}
