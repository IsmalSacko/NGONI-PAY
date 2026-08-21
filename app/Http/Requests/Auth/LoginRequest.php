<?php

namespace App\Http\Requests\Auth;

use App\Enums\Country;
use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Le numéro est l'unique identifiant de connexion. Il n'est pas
            // réécrit avant validation : c'est la recherche du compte qui essaie
            // ses différentes écritures ({@see phoneCandidates}).
            'phone' => 'required|string',
            // Facultatif : l'application le transmet pour lever l'ambiguïté d'un
            // numéro local, mais un commerçant qui saisit « +223… » se connecte
            // sans lui.
            'country' => ['sometimes', 'nullable', Rule::enum(Country::class)],
            'password' => 'required|string',
        ];
    }

    /**
     * Pays retenu pour interpréter le numéro saisi.
     *
     * À défaut, celui de la configuration : c'est l'hypothèse qui vaut pour les
     * comptes créés avant que le pays ne soit demandé.
     */
    public function country(): Country
    {
        $submitted = Country::tryFrom(strtoupper((string) $this->input('country')));

        return $submitted ?? Country::default();
    }

    /**
     * Écritures possibles du numéro saisi, pour retrouver le compte quelle que
     * soit la forme enregistrée.
     *
     * @return list<string>
     */
    public function phoneCandidates(): array
    {
        return PhoneNumber::candidates((string) $this->input('phone'), $this->country());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['country' => 'pays', 'phone' => 'téléphone'];
    }
}
