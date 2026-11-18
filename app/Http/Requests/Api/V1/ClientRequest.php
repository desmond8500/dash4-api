<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** Création (POST) et modification (PATCH) d'un client. */
class ClientRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'client_uuid' => $creating ? ['nullable', 'uuid'] : ['prohibited'],
            'name' => [$required, 'string', 'max:255'],
            'type' => ['sometimes', new Enum(ClientType::class)],
            'status' => ['sometimes', new Enum(ClientStatus::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_favorite' => ['sometimes', 'boolean'],
            ...PhoneEmailRules::rules(),
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nom', 'phones.*.number' => 'numéro', 'emails.*.address' => 'adresse e-mail'];
    }
}
