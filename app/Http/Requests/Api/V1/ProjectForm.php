<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Création (POST) et modification (PATCH) d'un projet. */
class ProjectForm extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'client_uuid' => $creating ? ['nullable', 'uuid'] : ['prohibited'],
            'name' => [$required, 'string', 'max:255'],
            'client_id' => [$required, 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', new Enum(ProjectStatus::class)],
            'manager_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'start_date' => ['sometimes', 'nullable', 'date'],
            // Comparée à la date de début seulement si elle est envoyée en même temps
            'end_date' => ['sometimes', 'nullable', 'date', ...($this->filled('start_date') ? ['after_or_equal:start_date'] : [])],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'client_id' => 'client',
            'manager_id' => 'chef de projet',
            'start_date' => 'date de début',
            'end_date' => 'date de fin',
        ];
    }
}
