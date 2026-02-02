<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // OPTION 1
            'phone' => 'required_without:client_id|string|max:20',
            'name' => 'nullable|string|max:255',
            'client_id' => 'nullable|exists:clients,id',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',

            'method' => 'required|in:cash,orange_money,moov_money,wave',
        ];
    }
}
