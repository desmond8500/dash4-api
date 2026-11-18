<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClientRequestSource;
use App\Enums\ClientRequestStatus;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Création (POST) et modification (PATCH) d'une demande client.
 * Saisie express : seul le texte de la demande est obligatoire.
 */
class ClientRequestForm extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'client_uuid' => $creating ? ['nullable', 'uuid'] : ['prohibited'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:5000'],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source' => ['sometimes', new Enum(ClientRequestSource::class)],
            'status' => ['sometimes', new Enum(ClientRequestStatus::class)],
            'priority' => ['sometimes', new Enum(Priority::class)],
            'assigned_to' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'remind_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'body' => 'demande',
            'client_id' => 'client',
            'contact_name' => 'nom du contact',
            'contact_phone' => 'téléphone du contact',
            'source' => 'origine',
            'priority' => 'priorité',
            'assigned_to' => 'personne en charge',
            'remind_at' => 'date de rappel',
        ];
    }
}
