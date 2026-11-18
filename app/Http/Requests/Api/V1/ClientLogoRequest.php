<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Logo d'un client : image JPEG, PNG ou WebP de 2 Mo au plus (pas de SVG, qui peut contenir du script). */
class ClientLogoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    public function attributes(): array
    {
        return ['logo' => 'logo'];
    }
}
