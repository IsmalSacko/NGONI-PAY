<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CallbackRequest;
use App\Models\Payment;
use App\Models\PaymentCallback;
use Illuminate\Support\Facades\DB;

class PaymentCallbackController extends Controller
{

    public function handle(CallbackRequest $request)
    {
        $payment = Payment::where(
            'transaction_ref',
            $request->transaction_ref
        )->first();

        if (! $payment) {
            return response()->json([
                'message' => 'Transaction inconnue',
                'transaction_ref' => $request->transaction_ref,
            ], 404);
        }

        // Audit callback
        PaymentCallback::create([
            'payment_id' => $payment->id,
            'provider' => $request->provider,
            'payload' => $request->payload,
            'signature' => $request->signature,
            'received_at' => now(),
        ]);

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Paiement déjà traité',
                'status' => $payment->status,
            ]);
        }

        if ($request->status === 'success') {
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
        } else {
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
            'signature' => $request->signature,
            'received_at' => now(),
        ]);
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
