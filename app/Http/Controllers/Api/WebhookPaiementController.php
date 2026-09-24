<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FournisseurPaiement;
use App\Http\Controllers\Controller;
use App\Services\Paiement\PaiementService;
use App\Services\Paiement\PasserelleIndisponible;
use App\Services\Paiement\PayDunyaPasserelle;
use App\Services\Paiement\PayPalPasserelle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notifications serveur-à-serveur des fournisseurs. Publiques (aucun jeton
 * Sanctum) : leur authenticité est vérifiée ici, et leur contenu n'est de
 * toute façon qu'un déclencheur — {@see PaiementService::synchroniser()}
 * relit l'état réel chez le fournisseur avant de confirmer quoi que ce soit.
 */
class WebhookPaiementController extends Controller
{
    public function __construct(private readonly PaiementService $paiements) {}

    public function paydunya(Request $request, PayDunyaPasserelle $passerelle): JsonResponse
    {
        $data = $request->input('data');

        // Selon la configuration du compte, `data` arrive soit en champs de
        // formulaire imbriqués, soit en JSON dans un seul champ.
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data) || ! $passerelle->notificationAuthentique($data['hash'] ?? null)) {
            return response()->json(['message' => 'Notification non authentique.'], 400);
        }

        $this->traiter(FournisseurPaiement::PayDunya, $data['invoice']['token'] ?? null, $data['custom_data']['paiement_id'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function paypal(Request $request, PayPalPasserelle $passerelle): JsonResponse
    {
        $entetes = [];
        foreach (['auth-algo', 'cert-url', 'transmission-id', 'transmission-sig', 'transmission-time'] as $nom) {
            $entetes["paypal-{$nom}"] = $request->header("paypal-{$nom}");
        }

        if (! $passerelle->webhookAuthentique($entetes, $request->getContent())) {
            return response()->json(['message' => 'Notification non authentique.'], 400);
        }

        $evenement = $request->json()->all();
        $ressource = $evenement['resource'] ?? [];

        [$commande, $paiementId] = match ($evenement['event_type'] ?? null) {
            'CHECKOUT.ORDER.APPROVED' => [$ressource['id'] ?? null, $ressource['purchase_units'][0]['custom_id'] ?? null],
            'PAYMENT.CAPTURE.COMPLETED' => [$ressource['supplementary_data']['related_ids']['order_id'] ?? null, $ressource['custom_id'] ?? null],
            default => [null, null],
        };

        $this->traiter(FournisseurPaiement::PayPal, $commande, $paiementId);

        return response()->json(['ok' => true]);
    }

    private function traiter(FournisseurPaiement $fournisseur, mixed $reference, mixed $paiementId): void
    {
        try {
            $this->paiements->traiterNotification(
                $fournisseur,
                is_string($reference) ? $reference : null,
                is_string($paiementId) ? $paiementId : null,
            );
        } catch (PasserelleIndisponible $e) {
            Log::info('Notification reçue mais fournisseur injoignable', ['fournisseur' => $fournisseur->value, 'raison' => $e->getMessage()]);
        }
    }
}
