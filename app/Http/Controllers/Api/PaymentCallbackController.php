<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CallbackRequest;
use App\Models\Payment;
use App\Models\PaymentCallback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{

    public function handle(CallbackRequest $request)
    {
        // $payment = Payment::where(
        //     'transaction_ref',
        //     $request->transaction_ref
        // )->first();
        $transactionRef = $request->transaction_ref
            ?? data_get($request->payload, 'custom_data.transaction_ref')
            ?? data_get($request->payload, 'invoice.custom_data.transaction_ref');

        $payment = Payment::where('transaction_ref', $transactionRef)->first();

        if (! $payment) {
            return response()->json([
                'message' => 'Transaction inconnue',
                'transaction_ref' => $transactionRef,
            ], 404);
        }
         if (! $this->isSignatureValid($request)) {
            Log::warning('Signature PayDunya invalide', [
                'payment_id' => $payment->id,
                'provider' => $request->provider,
            ]);

            return response()->json([
                'message' => 'Signature invalide',
            ], 401);
        }

        // Audit callback
        PaymentCallback::create([
            'payment_id' => $payment->id,
            'provider' => $request->provider,
            'payload' => $request->payload,
            'signature' => $request->signature ?? $request->header('PAYDUNYA-SIGNATURE'),
            'received_at' => now(),
        ]);

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Paiement déjà traité',
                'status' => $payment->status,
            ]);
        }

         $status = $this->normalizeStatus($request->status ?? data_get($request->payload, 'status'));

        if ($status === 'success') {
            $payment->update([
                'status' => 'success',
                'paid_at' => now(),
            ]);

            $payment->invoice()->firstOrCreate([
                'payment_id' => $payment->id,
            ], [
                'invoice_number' => 'NGONI-FACTURE' . now()->format('Ymd') . '-' . $payment->id,
                'total_amount' => $payment->amount,
                'sent_via' => 'none',
            ]);
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
            'provider' => $request->provider,
            'payload' => $request->payload,
             'signature' => $request->signature ?? $request->header('PAYDUNYA-SIGNATURE'),
            'received_at' => now(),
        ]);
    }

    private function isSignatureValid(CallbackRequest $request): bool
    {
        if ($request->provider !== 'paydunya') {
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

    // Validation du callback (à implémenter selon le provider)
    public function validatePayment(Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Paiement déjà traité',
                'status' => $payment->status,
            ]);
        }

        $payment->update([
            'status' => 'success',
            'paid_at' => now(),
        ]);

        $payment->invoice()->firstOrCreate(
            ['payment_id' => $payment->id],
            [
                'invoice_number' => 'NGONI-FACTURE' . now()->format('Ymd') . '-' . $payment->id,
                'total_amount' => $payment->amount,
                'sent_via' => 'none', // ✅ valeur autorisée
            ]
        );


        return response()->json([
            'message' => 'Paiement validé manuellement',
            'payment_status' => $payment->status,
        ]);
    }
}
