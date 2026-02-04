<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CallbackRequest;
use App\Models\Payment;
use App\Models\PaymentCallback;
use App\Http\Resources\InvoiceResource;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{

    public function handle(CallbackRequest $request)
    {
        $payload = $request->input('payload') ?? $request->all(); // Supporte JSON ou form-data
        $payloadData = data_get($payload, 'data', $payload);
        $provider = $request->input('provider', 'paydunya');
        $transactionRef = $request->transaction_ref
        // ici on essaie de récupérer le transaction_ref depuis plusieurs endroits possibles dans le payload
            ?? data_get($payloadData, 'custom_data.transaction_ref')
            ?? data_get($payloadData, 'invoice.custom_data.transaction_ref')
            ?? data_get($payload, 'custom_data.transaction_ref')
            ?? data_get($payload, 'invoice.custom_data.transaction_ref');

        $paymentId = data_get($payloadData, 'custom_data.payment_id');
        $status = $this->normalizeStatus(
            $request->status
                ?? data_get($payloadData, 'status')
                ?? data_get($payload, 'status')
        );

        Log::info('PayDunya callback raw', [
            'provider' => $provider,
            'status' => $status,
            'transaction_ref' => $transactionRef,
            'payment_id' => $paymentId,
            'signature' => $request->signature ?? $request->header('PAYDUNYA-SIGNATURE'),
            'payload' => $payload,
        ]);

        $payment = null;
        if ($transactionRef) {
            $payment = Payment::where('transaction_ref', $transactionRef)->first();
        }

        if (! $payment && $paymentId) {
            $payment = Payment::find($paymentId);
        }

        if (! $payment) {
            return response()->json([
                'message' => 'Transaction inconnue',
                'transaction_ref' => $transactionRef,
            ], 404);
        }
        if (! $this->isSignatureValid($request, $provider)) {
            Log::warning('Signature PayDunya invalide', [
                'payment_id' => $payment->id,
                'provider' => $provider,
            ]);

            return response()->json([
                'message' => 'Signature invalide',
            ], 401);
        }

        // Audit callback
        PaymentCallback::create([
            'payment_id' => $payment->id,
            'provider' => $provider,
            'payload' => $payload,
            'signature' => $request->signature ?? $request->header('PAYDUNYA-SIGNATURE'),
            'received_at' => now(),
            'starts_at' => now()->toDateString(),
        ]);

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Paiement déjà traité',
                'status' => $payment->status,
            ]);
        }

        if ($status === 'success') {
            DB::transaction(function () use ($payment) {
                $payment->update([
                    'status' => 'success',
                    'paid_at' => now(),
                ]);

                // 🟢 CAS ABONNEMENT
                if ($payment->purpose === 'subscription') {
                    Subscription::updateOrCreate(
                        ['business_id' => $payment->business_id],
                        [
                            'plan' => $this->planFromAmount($payment->amount),
                            'is_active' => true,
                            'starts_at' => now(),
                            'ends_at' => now()->addMonth(),
                        ]
                    );

                    $payment->invoice()->firstOrCreate(
                        ['payment_id' => $payment->id],
                        [
                            'invoice_number' => 'NGONI-ABO-' . now()->format('Ymd') . '-' . $payment->id,
                            'total_amount' => $payment->amount,
                            'sent_via' => 'none',
                        ]
                    );
                } else {
                    $payment->invoice()->firstOrCreate(
                        ['payment_id' => $payment->id],
                        [
                            'invoice_number' => 'NGONI-FACTURE-' . now()->format('Ymd') . '-' . $payment->id,
                            'total_amount' => $payment->amount,
                            'sent_via' => 'none',
                        ]
                    );
                }

                $payment->invoice()->firstOrCreate([
                    'payment_id' => $payment->id],
                    [
                    'invoice_number' => 'NGONI-FACTURE-' . now()->format('Ymd') . '-' . $payment->id,
                    'total_amount' => $payment->amount,
                    'sent_via' => 'none',
                ]);
            });

            $invoice = $payment->invoice()
                ->with(['payment.client'])
                ->first();

            if (! $invoice) {
                Log::error('Invoice non créée après callback success', [
                    'payment_id' => $payment->id,
                    'transaction_ref' => $transactionRef,
                ]);
                return response()->json([
                    'message' => 'Facture non créée',
                    'payment_id' => $payment->id,
                ], 500);
            }

            Log::info('Invoice créée après callback success', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ]);

            return new InvoiceResource($invoice);
        } elseif ($status === 'failed') {
            $payment->update(['status' => 'failed']);
        }

        return response()->json([
            'message' => 'Callback traité',
            'payment_status' => $payment->status,
        ]);
    }

    /**
     * Enregistrer le callback (audit)
     */
    private function storeCallback(Payment $payment, CallbackRequest $request): void
    {
        PaymentCallback::create([
            'payment_id' => $payment->id,
            'provider' => $request->input('provider', 'paydunya'),
            'payload' => $request->input('payload') ?? $request->all(),
             'signature' => $request->signature ?? $request->header('PAYDUNYA-SIGNATURE'),
            'received_at' => now(),
            'starts_at' => now()->toDateString(),
        ]);
    }

    private function isSignatureValid(CallbackRequest $request, string $provider): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if ($provider !== 'paydunya') {
            return true;
        }

        $expected = config('services.paydunya.webhook_secret');
        if (! $expected) {
            return true;
        }

        $signature = $request->header('PAYDUNYA-SIGNATURE') ?? $request->signature;

        if (! $signature) {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    private function normalizeStatus(?string $status): string
    {
        $status = strtolower((string) $status);

        return match ($status) {
            'success', 'completed' => 'success',
            'failed', 'error' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Paiement réussi
     */
    private function markAsSuccess(Payment $payment): void
    {
        // Sécurité : éviter double facture
        if ($payment->invoice()->exists()) {
            return;
        }

        $payment->update([
            'status' => 'success',
            'paid_at' => now(),
        ]);

        $payment->invoice()->create([
            'invoice_number' => 'NGONI-FACTURE' . now()->format('Ymd') . '-' . $payment->id,
            'total_amount' => $payment->amount,
            'sent_via' => 'none',
        ]);
    }

    private function planFromAmount($amount): string
    {
        $value = (int) round((float) $amount);

        return match ($value) {
            5000 => 'basic',
            15000 => 'pro',
            default => 'free',
        };
    }


}
