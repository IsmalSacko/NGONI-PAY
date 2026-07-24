<?php

namespace App\Http\Requests\Admin;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSystemAdmin();
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::in([
                Subscription::PLAN_FREE,
                Subscription::PLAN_BASIC,
                Subscription::PLAN_PRO,
            ])],
            // À vie : ignore ends_at.
            'lifetime' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            // Requis seulement si non "à vie".
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at', 'required_if:lifetime,false'],
            'admin_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.required_if' => "Une date de fin est requise si l'abonnement n'est pas à vie.",
            'ends_at.after_or_equal' => 'La date de fin doit être postérieure à la date de début.',
        ];
    }
}
