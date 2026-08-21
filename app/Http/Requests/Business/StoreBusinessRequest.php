<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessType;
use App\Support\Money\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['currency' => 'devise', 'type' => "secteur d'activité"];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            // Borné au catalogue : la colonne acceptait n'importe quelle chaîne
            // depuis qu'elle n'est plus un `enum`, et un secteur inventé
            // s'afficherait tel quel sur les factures.
            'type' => ['required', Rule::enum(BusinessType::class)],
            'address' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            // Facultative : à défaut, celle du pays du propriétaire. Bornée au
            // catalogue, sinon un code inventé s'afficherait derrière chaque
            // montant, jusque sur les factures remises aux clients.
            'currency' => ['sometimes', 'nullable', Rule::in(Currencies::codes())],
        ];
    }
}
