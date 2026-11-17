<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Nom de l'appareil, affiché dans la liste des sessions (ex. « Web », « Téléphone de Paul »)
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
