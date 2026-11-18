<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contact */
class ContactResource extends JsonResource
{
    use PhoneEmailFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),
            'civility' => $this->civility
                ? ['value' => $this->civility->value, 'label' => $this->civility->label()]
                : null,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'full_name' => $this->full_name,
            'job_title' => $this->job_title,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            ...$this->phoneEmailFields(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
