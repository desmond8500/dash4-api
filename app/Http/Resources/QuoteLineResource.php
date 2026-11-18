<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'designation' => $this->designation,
            'reference' => $this->reference,
            'comment' => $this->comment,
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
