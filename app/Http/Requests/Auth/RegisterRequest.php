<?php

namespace App\Http\Requests\Auth;

use App\Enums\Country;
use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Le numéro est mis au format international avant validation, dans le pays
     * choisi.
     *
     * L'unicité doit porter sur la forme enregistrée, pas sur la saisie : sinon
     * « 76008201 » et « +22376008201 » passeraient tous les deux et le même
     * commerçant aurait deux comptes.
     *
     * Le pays est facultatif : les versions de l'application déjà installées ne
     * l'envoient pas, et leurs inscriptions doivent continuer d'aboutir. À
     * défaut, celui de la configuration.
     */
    protected function prepareForValidation(): void
    {
        $submitted = Country::tryFrom(strtoupper((string) $this->input('country')));

        // Un code envoyé mais inconnu n'est pas remplacé en silence : il ressort
        // en erreur de validation. Le corriger d'office enregistrerait un
        // commerçant dans un pays qu'il n'a pas choisi, et le numéro qui va avec.
        if ($submitted === null && $this->filled('country')) {
            return;
        }

        $country = $submitted ?? Country::default();

        $this->merge([
            'country' => $country->value,
            'phone' => PhoneNumber::normalize((string) $this->input('phone'), $country),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            // Le pays commande l'indicatif du numéro et la devise proposée aux
            // business créés ensuite.
            'country' => ['required', Rule::enum(Country::class)],
            // Le numéro vient d'être mis au format international : s'il ne l'est
            // pas, c'est qu'il n'a pas pu être compris — mieux vaut le dire que
            // d'enregistrer un identifiant de connexion inutilisable.
            'phone' => [
                'required', 'string', 'max:30',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                        $fail('Ce numéro de téléphone ne semble pas valide.');
                    }
                },
                'unique:users,phone',
            ],
            'email' => 'nullable|email|unique:users',
            'password' => 'required|min:6',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['country' => 'pays', 'phone' => 'téléphone'];
    }
}
