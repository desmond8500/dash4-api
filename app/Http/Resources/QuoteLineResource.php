<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\QuoteLine — prix avec prices.view, coût et coefficient avec costs.view. */
class QuoteLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'quote_section_id' => $this->quote_section_id,
            'position' => $this->position,
            'article_id' => $this->article_id,
            // Article du stock lié : fiche consultable, photo à gauche de la ligne
            'article' => $this->when($this->relationLoaded('article'), fn () => $this->article ? [
                'id' => $this->article->id,
                'designation' => $this->article->designation,
                'reference' => $this->article->reference,
                'image_url' => $this->article->image_path ? Storage::disk('public')->url($this->article->image_path) : null,
                'quantity' => $this->article->quantity,
                'unit' => $this->article->unit,
                'deleted' => $this->article->trashed(),
            ] : null),
            'designation' => $this->designation,
            'reference' => $this->reference,
            'comment' => $this->comment,
            'show_in_quantities' => (bool) ($this->show_in_quantities ?? true),
            'category' => ['value' => $this->category->value, 'label' => $this->category->label()],
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            $this->mergeWhen((bool) $user?->can('prices.view'), fn () => [
                'unit_price' => $this->unit_price,
                'line_total' => $this->line_total,
            ]),
            $this->mergeWhen((bool) $user?->can('costs.view'), fn () => [
                'unit_cost' => $this->unit_cost,
                'coefficient' => $this->coefficient,
            ]),
        ];
    }
}
