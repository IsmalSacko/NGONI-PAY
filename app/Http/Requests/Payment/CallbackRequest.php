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
            'transaction_ref' => 'nullable|string',
            'status' => 'nullable|in:success,failed,pending,completed',
            'provider' => 'nullable|in:orange_money,moov_money,wave,paydunya',
            'payload' => 'nullable|array',
            'signature' => 'nullable|string',
        ];
    }
}
