<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Quote
 * Montants : prices.view ; coûts et marge : costs.view (jamais pour un technicien).
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $prices = (bool) $user?->can('prices.view');
        $costs = (bool) $user?->can('costs.view');
        $net = $this->subtotal - $this->discount_amount;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'number' => $this->number,
            'title' => $this->title,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'introduction' => $this->introduction,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'issued_at' => $this->issued_at?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'tax_mode' => ['value' => $this->tax_mode->value, 'label' => $this->tax_mode->label()],
            'vat_rate' => $this->vat_rate,
            'brs_rate' => $this->brs_rate,
            'discount_rate' => $this->discount_rate,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount
                ? ['id' => $this->bankAccount->id, 'bank_name' => $this->bankAccount->bank_name, 'label' => $this->bankAccount->label]
                : null),
            'show_signature' => $this->show_signature,
            'is_favorite' => $this->is_favorite,
            $this->mergeWhen($prices, fn () => [
                'subtotal' => $this->subtotal,
                'discount_amount' => $this->discount_amount,
                'net_amount' => $net,
                'tax_amount' => $this->tax_amount,
                'total' => $this->total,
            ]),
            $this->mergeWhen($costs, fn () => [
                'cost_total' => $this->cost_total,
                'margin' => (int) round($net - $this->cost_total),
                'margin_rate' => $net > 0 ? round(($net - $this->cost_total) / $net * 100, 1) : null,
            ]),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'position' => $s->position,
                'is_visible' => $s->is_visible,
                ...($prices ? ['subtotal' => (int) $s->lines->sum('line_total')] : []),
                'lines' => QuoteLineResource::collection($s->lines)->resolve($request),
            ])->values()),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_document_id' => $this->signed_document_id,
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'validate' => (bool) $user?->can('validate', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'export' => (bool) $user?->can('export', $this->resource),
                'edit_prices' => $costs,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
