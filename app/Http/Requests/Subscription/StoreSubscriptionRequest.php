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
            // Seuls les plans payants se demandent : l'essai démarre tout seul.
            'plan' => 'required|in:basic,pro',
            // Moyen annoncé par le commerçant, à titre indicatif pour l'exploitant.
            'method' => 'nullable|in:cash,orange_money,moov_money,wave,bank_transfer',
            // Durée souscrite. Facultative : les versions de l'application déjà
            // installées ne l'envoient pas, et retombent sur le mensuel.
            'cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
        ];
    }
}
