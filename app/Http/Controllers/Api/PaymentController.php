<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Business;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Requests\Payment\StorePaymentRequest;

class PaymentController extends Controller
{
    public function store(
        StorePaymentRequest $request,
        Business $business
    ) {
        $payment = Payment::create([
            'business_id' => $business->id,
            'client_id' => $request->client_id,
            'user_id' => $request->user()->id,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'method' => $request->method,
            'transaction_ref' => Str::uuid(),
            'status' => 'pending',
        ]);

        return new PaymentResource($payment);
    }

    public function index(Request $request)
    {
        $payments = Payment::where('business_id', $request->user()->business_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return PaymentResource::collection($payments);
    }

    public function show(Request $request, $id)
    {
        $payment = Payment::where('business_id', $request->user()->business_id)
            ->where('id', $id)
            ->firstOrFail();

        return new PaymentResource($payment);
    }
}
