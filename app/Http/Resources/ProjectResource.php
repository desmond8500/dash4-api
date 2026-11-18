<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'name' => $this->name,
            'description' => $this->description,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name]
                : null),
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => AttachmentResources::author($this->manager)),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'is_favorite' => $this->is_favorite,
            'notes_count' => $this->whenCounted('notes'),
            'links_count' => $this->whenCounted('links'),
            'documents_count' => $this->whenCounted('documents'),
            'notes' => NoteResource::collection($this->whenLoaded('notes')),
            'links' => LinkResource::collection($this->whenLoaded('links')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'can_update' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
