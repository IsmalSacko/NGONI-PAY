<?php

namespace App\Http\Requests\Web;

use App\Services\PhoneService;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $login = trim((string) $this->input('login'));

        if ($login !== '' && ! str_contains($login, '@')) {
            $login = app(PhoneService::class)->normalize($login);
        }

        $this->merge(['login' => $login]);
    }
}
