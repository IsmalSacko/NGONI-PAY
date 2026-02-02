<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;

class InvoiceController extends Controller
{
    public function show(Payment $payment)
    {
        $invoice = $payment->invoice()
            ->with(['payment.client'])
            ->firstOrFail();

        return new InvoiceResource($invoice);
    }
}
