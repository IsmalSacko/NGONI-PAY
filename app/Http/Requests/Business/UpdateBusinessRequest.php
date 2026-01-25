<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
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
            'name' => 'sometimes|string|max:150',
            'type' => 'sometimes|string|max:50',
            'address' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
