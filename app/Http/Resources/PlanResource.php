<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Plan — le fichier se télécharge par `download_path` (jeton requis). Chaque repère donne
 * l'icône affichée (`icon_id` : la sienne, sinon celle de l'article de l'équipement) et son calque (`layer_id` : système).
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $renders = collect($this->renders ?? [])->map(fn ($r, $page) => [
            'page' => (int) $page,
            'path' => "plans/{$this->id}/render/{$page}",
            'width' => $r['width'] ?? null,
            'height' => $r['height'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ])->values();

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
            'settings' => [
                'hidden_layers' => array_values($this->setting('hidden_layers', [])),
                'legend' => (bool) $this->setting('legend', true),
                'labels' => (bool) $this->setting('labels', true),
            ],
            'renders' => $renders,
            'markers' => $this->whenLoaded('markers', fn () => $this->markers->map(fn ($m) => [
                'id' => $m->id,
                'client_uuid' => $m->client_uuid,
                'equipment_id' => $m->equipment_id,
                'plan_icon_id' => $m->plan_icon_id,
                'installed_system_id' => $m->installed_system_id,
                'icon_id' => $m->effectiveIconId(),
                'layer_id' => $m->layerId(),
                'page' => $m->page,
                'x' => $m->x,
                'y' => $m->y,
                'size' => $m->size,
                'rotation' => $m->rotation,
                'label' => $m->label,
                'show_label' => $m->show_label,
                'color' => $m->color,
            ])->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
