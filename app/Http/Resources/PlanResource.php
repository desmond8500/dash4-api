<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Plan — le fichier se télécharge par `download_path` (jeton requis). */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'building_id' => $this->building_id,
            'level_id' => $this->level_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'mime' => $this->mime,
            'size' => $this->size,
            'position' => $this->position,
            'download_path' => "plans/{$this->id}/file",
            'markers' => $this->whenLoaded('markers', fn () => $this->markers->map(fn ($m) => [
                'id' => $m->id,
                'client_uuid' => $m->client_uuid,
                'equipment_id' => $m->equipment_id,
                'page' => $m->page,
                'x' => $m->x,
                'y' => $m->y,
                'label' => $m->label,
                'color' => $m->color,
            ])->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
