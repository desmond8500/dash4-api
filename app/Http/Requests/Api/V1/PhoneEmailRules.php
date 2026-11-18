<?php

namespace App\Http\Requests\Api\V1;

/** Règles des listes `phones` et `emails` (numéros et adresses supplémentaires). */
final class PhoneEmailRules
{
    public static function rules(): array
    {
        return [
            'phones' => ['sometimes', 'array', 'max:20'],
            'phones.*.label' => ['nullable', 'string', 'max:50'],
            'phones.*.number' => ['required', 'string', 'max:50'],
            'emails' => ['sometimes', 'array', 'max:20'],
            'emails.*.label' => ['nullable', 'string', 'max:50'],
            'emails.*.address' => ['required', 'email', 'max:255'],
        ];
    }
}
