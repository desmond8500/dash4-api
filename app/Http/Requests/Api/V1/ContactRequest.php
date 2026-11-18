<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Civility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Création (POST) et modification (PATCH) d'un contact. */
class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'client_uuid' => $creating ? ['nullable', 'uuid'] : ['prohibited'],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'civility' => ['sometimes', 'nullable', new Enum(Civility::class)],
            'firstname' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'lastname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...PhoneEmailRules::rules(),
        ];
    }

    public function attributes(): array
    {
        return ['firstname' => 'prénom', 'client_id' => 'client'];
    }
}
