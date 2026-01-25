<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // provider externe
    }

    public function rules(): array
    {
        return [
            'transaction_ref' => 'required|string',
            'status' => 'required|in:success,failed',
            'provider' => 'required|in:orange_money,moov_money,wave',
            'payload' => 'required|array',
            'signature' => 'nullable|string',
        ];
    }
}
