<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => 'sometimes|in:free,basic,pro',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|nullable|date|after_or_equal:starts_at',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
