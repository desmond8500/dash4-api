<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contract — montants seulement avec `prices.view`. */
class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $prices = (bool) $user?->can('prices.view');
        $today = today();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'title' => $this->title,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'tacit_renewal' => $this->tacit_renewal,
            'billing_period' => ['value' => $this->billing_period->value, 'label' => $this->billing_period->label()],
            'next_invoice_date' => $this->next_invoice_date?->toDateString(),
            'invoice_due' => $this->status->value === 'active' && $this->next_invoice_date !== null && $this->next_invoice_date->lte($today),
            'ends_soon' => $this->end_date !== null && $this->end_date->gte($today) && $this->end_date->lte($today->copy()->addDays($this->notice_days)),
            'visits_per_year' => $this->visits_per_year,
            'notice_days' => $this->notice_days,
            'description' => $this->description,
            'terms' => $this->terms,
            'period_amount' => $this->when($prices && $this->relationLoaded('lines'), fn () => $this->periodAmount()),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'flat_rate_id' => $l->flat_rate_id,
                'designation' => $l->designation,
                'unit' => $l->unit,
                'quantity' => $l->quantity,
                ...($prices ? ['unit_price' => $l->unit_price, 'line_total' => round($l->quantity * $l->unit_price, 2)] : []),
            ])->values()),
            'invoices' => $this->when($user?->can('invoices.view') && $this->relationLoaded('invoices'), fn () => $this->invoices->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->number,
                'status' => ['value' => $i->status->value, 'label' => $i->status->label()],
                'period_start' => $i->period_start?->toDateString(),
                'period_end' => $i->period_end?->toDateString(),
                ...($prices ? ['total' => $i->total] : []),
            ])->values()),
            'visits' => $this->whenLoaded('visits', fn () => $this->visits->map(fn ($v) => [
                'id' => $v->id, 'number' => $v->number, 'planned_on' => $v->planned_on?->toDateString(),
                'task' => $v->task ? ['id' => $v->task->id, 'title' => $v->task->title, 'status' => ['value' => $v->task->status->value, 'label' => $v->task->status->label()]] : null,
            ])->values()),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'invoice' => (bool) $user?->can('create', \App\Models\Invoice::class),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
