<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Quantity — articles sans prix ; le devis créé n'est montré qu'à qui voit les devis. */
class QuantityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quotes = (bool) $request->user()?->canAny(['quotes.view', 'quotes.view.own']);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'building_id' => $this->building_id,
            'installed_system_id' => $this->installed_system_id,
            'name' => $this->name,
            'description' => $this->description,
            'quote' => $this->when($quotes, fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number, 'title' => $this->quote->title] : null),
            'rows' => $this->whenLoaded('rows', fn () => $this->rows->map(fn ($r) => [
                'id' => $r->id,
                'article_id' => $r->article_id,
                'article' => $r->article ? ['id' => $r->article->id, 'designation' => $r->article->designation, 'reference' => $r->article->reference] : null,
                'designation' => $r->designation,
                'unit' => $r->unit,
                'quantity' => $r->quantity,
                'level_id' => $r->level_id,
                'room_id' => $r->room_id,
                'description' => $r->description,
                'position' => $r->position,
            ])->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
