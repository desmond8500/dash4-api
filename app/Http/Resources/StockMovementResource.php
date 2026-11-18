<?php

namespace App\Http\Resources;

use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StockMovement — coût d'entrée avec costs.view. */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->relationLoaded('source') ? $this->source : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'article_id' => $this->article_id,
            'article' => $this->whenLoaded('article', fn () => $this->article ? [
                'id' => $this->article->id, 'designation' => $this->article->designation, 'reference' => $this->article->reference, 'unit' => $this->article->unit,
            ] : null),
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'quantity' => $this->quantity,
            'quantity_after' => $this->quantity_after,
            'reason' => $this->reason,
            'source' => $this->source_type ? [
                'type' => $this->source_type,
                'id' => $this->source_id,
                'number' => $source?->number ?? null,
                'quote_id' => $source instanceof DeliveryNote ? $source->quote_id : null,
            ] : null,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            $this->mergeWhen((bool) $request->user()?->can('costs.view'), fn () => ['unit_cost' => $this->unit_cost]),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'moved_at' => $this->moved_at?->toIso8601String(),
        ];
    }
}
