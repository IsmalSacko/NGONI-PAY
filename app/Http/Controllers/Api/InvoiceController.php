<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function show(Request $request, Payment $payment)
    {
        // Sécurité : seul le propriétaire ou un membre du business peut voir la facture.
        $business = $payment->business;
        $user = $request->user();

        $isMember = $business
            && ($business->owner_id === $user->id
                || $business->staff()->where('user_id', $user->id)->exists());

        if (! $isMember) {
            abort(403, 'Accès interdit');
        }

        $invoice = $payment->invoice()
            ->with(['payment.client'])
            ->firstOrFail();

        return new InvoiceResource($invoice);
    }
}
