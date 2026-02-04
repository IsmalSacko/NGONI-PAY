<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Client;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayDunyaClient
{
    public function createInvoice(Payment $payment, Client $client): array
    {
        $guardResponse = $this->guardAgainstLiveKeysInLocal();
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $businessName = optional($payment->business)->name ?: config('app.name');

        $payload = [
            'invoice' => [
                'total_amount' => (float) $payment->amount,
                'description' => 'Paiement NGONI PAY ' . $payment->transaction_ref,
            ],
            'store' => [
                'name' => $businessName,
                'website_url' => config('app.url'),
            ],
            'custom_data' => [
                'payment_id' => $payment->id,
                'transaction_ref' => $payment->transaction_ref,
                'client_phone' => $client->phone,
            ],
        ];

        $response = Http::withHeaders([
            'PAYDUNYA-MASTER-KEY' => config('services.paydunya.master_key'),
            'PAYDUNYA-PRIVATE-KEY' => config('services.paydunya.private_key'),
            'PAYDUNYA-PUBLIC-KEY' => config('services.paydunya.public_key'),
            'PAYDUNYA-TOKEN' => config('services.paydunya.token'),
        ])->post(rtrim(config('services.paydunya.base_url'), '/') . '/v1/checkout-invoice/create', $payload);

        return [
            'successful' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    public function createInvoiceForSubscription(Payment $payment, Business $business): array
    {
        $guardResponse = $this->guardAgainstLiveKeysInLocal();
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $businessName = $business->name ?: config('app.name');

        $payload = [
            'invoice' => [
                'total_amount' => (float) $payment->amount,
                'description' => 'Abonnement NGONI PAY ' . $payment->transaction_ref,
            ],
            'store' => [
                'name' => $businessName,
                'website_url' => config('app.url'),
            ],
            'custom_data' => [
                'payment_id' => $payment->id,
                'transaction_ref' => $payment->transaction_ref,
                'purpose' => 'subscription',
            ],
        ];

        $response = Http::withHeaders([
            'PAYDUNYA-MASTER-KEY' => config('services.paydunya.master_key'),
            'PAYDUNYA-PRIVATE-KEY' => config('services.paydunya.private_key'),
            'PAYDUNYA-PUBLIC-KEY' => config('services.paydunya.public_key'),
            'PAYDUNYA-TOKEN' => config('services.paydunya.token'),
        ])->post(rtrim(config('services.paydunya.base_url'), '/') . '/v1/checkout-invoice/create', $payload);

        return [
            'successful' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    private function guardAgainstLiveKeysInLocal(): ?array
    {
        if (app()->environment('production')) {
            return null;
        }

        $baseUrl = (string) config('services.paydunya.base_url');
        $publicKey = (string) config('services.paydunya.public_key');
        $privateKey = (string) config('services.paydunya.private_key');
        $token = (string) config('services.paydunya.token');

        $usesLiveKey = Str::startsWith($publicKey, 'live_')
            || Str::startsWith($privateKey, 'live_')
            || Str::startsWith($token, 'live_');

        $isSandbox = Str::contains($baseUrl, 'sandbox');

        if ($usesLiveKey || ! $isSandbox) {
            return [
                'successful' => false,
                'status' => 400,
                'data' => [
                    'message' => 'Configuration PayDunya invalide en local : utilisez uniquement les cles de test et l URL sandbox.',
                ],
            ];
        }

        return null;
    }
}
