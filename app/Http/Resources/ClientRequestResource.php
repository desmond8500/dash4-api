<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ClientRequest */
class ClientRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = fn ($u) => $u ? ['id' => $u->id, 'full_name' => $u->full_name] : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'body' => $this->body,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name]
                : null),
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'source' => ['value' => $this->source->value, 'label' => $this->source->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => $user($this->assignee)),
            'creator' => $this->whenLoaded('creator', fn () => $user($this->creator)),
            'processor' => $this->whenLoaded('processor', fn () => $user($this->processor)),
            'remind_at' => $this->remind_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'converted_to' => $this->converted_to_type
                ? ['type' => $this->converted_to_type, 'id' => $this->converted_to_id]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
