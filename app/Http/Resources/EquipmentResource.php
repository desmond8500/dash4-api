<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Equipment — l'article lié est donné sans aucun prix. */
class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'installed_system_id' => $this->installed_system_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'type' => $this->type,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'quantity' => $this->quantity,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'characteristics' => array_values(array_map(
                fn ($c) => ['label' => (string) ($c['label'] ?? ''), 'value' => (string) ($c['value'] ?? '')],
                $this->characteristics ?? [],
            )),
            'description' => $this->description,
            'position' => $this->position,
            'article_id' => $this->article_id,
            'article' => $this->whenLoaded('article', fn () => $this->article ? ['id' => $this->article->id, 'designation' => $this->article->designation, 'reference' => $this->article->reference] : null),
            'level_id' => $this->level_id,
            'room_id' => $this->room_id,
            'documents_count' => $this->whenCounted('documents'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
