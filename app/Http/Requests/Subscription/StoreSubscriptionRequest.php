<?php

namespace App\Http\Requests\Subscription;

use App\Enums\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => 'required|in:free,basic,pro',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'method' => 'required_unless:plan,free|in:cash,orange_money,moov_money,wave,bank_transfer',
            // Durée souscrite. Facultative : les versions de l'application déjà
            // installées ne l'envoient pas, et retombent sur le mensuel.
            'cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
        ];
    }
}
