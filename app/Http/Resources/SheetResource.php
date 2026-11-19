<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Sheet */
class SheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'building_id' => $this->building_id,
            'installed_system_id' => $this->installed_system_id,
            'title' => $this->title,
            'type' => $this->type,
            'date' => $this->date?->toDateString(),
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'notes' => $this->notes,
            'zones' => $this->whenLoaded('zones', fn () => $this->zones->map(fn ($z) => $z->only(['id', 'position', 'number', 'name', 'code', 'equipment_label', 'equipment_id', 'room_id']))->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
