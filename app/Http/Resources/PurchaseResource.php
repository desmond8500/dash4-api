<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Purchase — coûts et montants avec costs.view. */
class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = (bool) $user?->can('costs.view');

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'number' => $this->number,
            'title' => $this->title,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? $this->provider->only(['id', 'name', 'phone', 'email']) : null),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'ordered_at' => $this->ordered_at?->toDateString(),
            'expected_at' => $this->expected_at?->toDateString(),
            'is_late' => $this->status->receivable() && $this->expected_at?->isPast() && ! $this->expected_at->isToday(),
            'received_at' => $this->received_at?->toIso8601String(),
            'tax_mode' => ['value' => $this->tax_mode->value, 'label' => $this->tax_mode->label()],
            'vat_rate' => $this->vat_rate,
            $this->mergeWhen($costs, fn () => ['subtotal' => $this->subtotal, 'tax_amount' => $this->tax_amount, 'total' => $this->total]),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'article_id' => $l->article_id,
                'article' => $l->article ? ['id' => $l->article->id, 'designation' => $l->article->designation, 'quantity' => $l->article->quantity] : null,
                'designation' => $l->designation,
                'reference' => $l->reference,
                'unit' => $l->unit,
                'quantity' => $l->quantity,
                'received_quantity' => $l->received_quantity,
                'remaining_quantity' => $l->remaining(),
                ...($costs ? ['unit_cost' => $l->unit_cost, 'line_total' => $l->line_total] : []),
            ])->values()),
            'receptions' => $this->whenLoaded('receptions', fn () => $this->receptions->map(fn ($r) => [
                'id' => $r->id,
                'received_at' => $r->received_at?->toIso8601String(),
                'lines' => $r->lines,
                'note' => $r->note,
                'receiver' => AttachmentResources::author($r->receiver),
            ])->values()),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'edit' => $this->isDraft() && (bool) $user?->can('update', $this->resource),
                'order' => (bool) $user?->can('order', $this->resource),
                'receive' => $this->status->receivable() && (bool) $user?->can('receive', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
