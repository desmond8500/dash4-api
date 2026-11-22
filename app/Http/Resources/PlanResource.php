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
                // Légende : sous le plan, à droite, ou posée sur le plan (coin haut gauche en position relative)
                'legend_position' => $this->setting('legend_position', 'below'),
                'legend_x' => (float) $this->setting('legend_x', 0.7),
                'legend_y' => (float) $this->setting('legend_y', 0.7),
                'legend_scale' => (float) $this->setting('legend_scale', 1),
            ],
            'renders' => $renders,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'building' => $this->whenLoaded('building', fn () => $this->building ? ['id' => $this->building->id, 'name' => $this->building->name] : null),
            'level' => $this->whenLoaded('level', fn () => $this->level ? ['id' => $this->level->id, 'name' => $this->level->name] : null),
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
