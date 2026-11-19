<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Project — chantier d'un projet : bâtiments (niveaux, pièces), systèmes installés
 * (équipements, avancement), avancement global et droits.
 */
class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $systems = $this->installedSystems;
        $equipmentCount = $systems->sum(fn ($s) => $s->equipment->sum('quantity'));

        return [
            'project' => ['id' => $this->id, 'name' => $this->name],
            'buildings' => $this->buildings->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'description' => $b->description,
                'position' => $b->position,
                'levels' => $b->levels->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'description' => $l->description,
                    'position' => $l->position,
                    'rooms' => $l->rooms->map(fn ($r) => $r->only(['id', 'name', 'description', 'position']))->values(),
                ])->values(),
            ])->values(),
            'systems' => InstalledSystemResource::collection($systems),
            // Moyenne des systèmes, sans pondération (chaque système compte autant)
            'progress' => $systems->isEmpty() ? 0 : (int) round($systems->avg('progress')),
            'equipment_count' => $equipmentCount,
            'reception_reports_count' => $this->reception_reports_count ?? 0,
            'can' => [
                'manage' => (bool) $user?->can('manageSite', $this->resource),
                'create' => (bool) ($user?->can('sites.create') && $user->can('manageSite', $this->resource)),
                'delete' => (bool) $user?->can('deleteSite', $this->resource),
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
