<?php

namespace App\Http\Requests\Subscription;

use App\Enums\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dépôt d'une demande d'abonnement.
 *
 * La preuve de paiement est facultative : un commerçant qui règle de la main à la
 * main n'a ni reçu ni SMS à joindre, et l'exiger bloquerait sa demande.
 */
class StoreSubscriptionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Le plan est validé contre le catalogue tenu par l'exploitant : il
            // peut en ouvrir ou en fermer sans qu'on touche au code.
            'plan' => [
                'required',
                Rule::exists('subscription_plans', 'code')->where('is_active', true),
            ],
            'cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'method' => ['sometimes', 'nullable', 'in:cash,orange_money,moov_money,wave,bank_transfer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Photo du reçu ou capture du SMS de confirmation.
            'proof' => ['sometimes', 'nullable', 'image', 'max:4096'],
            // Ou le texte du SMS, recopié — tous les téléphones ne permettent pas
            // d'en faire une capture facilement.
            'proof_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'plan' => 'plan',
            'cycle' => 'durée',
            'proof' => 'preuve de paiement',
            'contact_phone' => 'numéro à rappeler',
        ];
    }
}
