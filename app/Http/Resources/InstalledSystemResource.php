<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InstalledSystem */
class InstalledSystemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'building_id' => $this->building_id,
            'system_id' => $this->system_id,
            'system' => $this->whenLoaded('system', fn () => $this->system ? ['id' => $this->system->id, 'name' => $this->system->name, 'icon' => $this->system->icon] : null),
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'progress' => $this->progress,
            'progress_entries' => $this->whenLoaded('progressEntries', fn () => $this->progressEntries->take(20)->map(fn ($p) => [
                'id' => $p->id,
                'date' => $p->date?->toDateString(),
                'percent' => $p->percent,
                'note' => $p->note,
                'creator' => AttachmentResources::author($p->creator),
            ])->values()),
            'equipment' => EquipmentResource::collection($this->whenLoaded('equipment')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
