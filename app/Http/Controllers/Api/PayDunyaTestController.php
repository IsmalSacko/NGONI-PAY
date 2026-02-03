<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Business;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Services\Payments\PayDunyaClient;

class PayDunyaTestController extends Controller
{
    public function create(Request $request, Business $business, PayDunyaClient $payDunyaClient)
    {
        $this->authorizeOwner($business, $request);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['nullable', 'string'],
            'phone' => ['required', 'string'],
            'name' => ['nullable', 'string'],
        ]);

        $method = $validated['method'] ?? 'orange_money';
        $client = Client::firstOrCreate(
            [
                'phone' => $this->normalizePhone($validated['phone']),
                'business_id' => $business->id,
            ],
            [
                'name' => $validated['name'] ?? 'Client test',
            ]
        );

        if (! empty($validated['name']) && $client->name !== $validated['name']) {
            $client->update(['name' => $validated['name']]);
        }

        $payment = Payment::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'] ?? 'XOF',
            'method' => $method,
            'provider' => 'paydunya',
            'transaction_ref' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        $response = $payDunyaClient->createInvoice($payment, $client);

        if (! $response['successful']) {
            throw ValidationException::withMessages([
                'paydunya' => ['Impossible de créer la facture PayDunya pour le moment.'],
            ]);
        }

        $payment->update([
            'provider_reference' => data_get($response['data'], 'token')
                ?? data_get($response['data'], 'response_code'),
            'provider_checkout_url' => data_get($response['data'], 'invoice_url')
                ?? data_get($response['data'], 'redirect_url')
                ?? data_get($response['data'], 'response_text'),
        ]);

        return new PaymentResource($payment->load('client'));
    }

    private function authorizeOwner(Business $business, Request $request): void
    {
        if ($business->owner_id !== $request->user()->id) {
            abort(403, 'Accès interdit');
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = str_replace(' ', '', $phone);

        if (str_starts_with($phone, '+223')) {
            return $phone;
        }

        if (preg_match('/^\\d{8}$/', $phone)) {
            return '+223' . $phone;
        }

        if (preg_match('/^223\\d{8}$/', $phone)) {
            return '+' . $phone;
        }

        return $phone;
    }
}
