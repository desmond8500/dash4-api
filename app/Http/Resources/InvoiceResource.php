<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice — réservé à invoices.view (jamais un technicien). */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'number' => $this->number,
            'title' => $this->title,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'scope' => ['value' => $this->scope->value, 'label' => $this->scope->label()],
            'percent' => $this->percent,
            'quote_id' => $this->quote_id,
            'quote' => $this->whenLoaded('quote', fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number, 'title' => $this->quote->title, 'total' => $this->quote->total] : null),
            'quote_deposit_id' => $this->quote_deposit_id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'introduction' => $this->introduction,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'tax_mode' => ['value' => $this->tax_mode->value, 'label' => $this->tax_mode->label()],
            'vat_rate' => $this->vat_rate,
            'brs_rate' => $this->brs_rate,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount ? ['id' => $this->bankAccount->id, 'bank_name' => $this->bankAccount->bank_name, 'label' => $this->bankAccount->label] : null),
            'show_signature' => $this->show_signature,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remainingAmount(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => $l->only(['id', 'position', 'designation', 'reference', 'unit', 'quantity', 'unit_price', 'line_total']))->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'paid_at' => $p->paid_at?->toDateString(),
                'method' => ['value' => $p->method->value, 'label' => $p->method->label()],
                'reference' => $p->reference,
                'note' => $p->note,
                'creator' => AttachmentResources::author($p->creator),
            ])->values()),
            'emails' => $this->whenLoaded('emails', fn () => $this->emails->map(fn ($m) => [
                'id' => $m->id, 'to' => $m->to, 'cc' => $m->cc ?? [], 'subject' => $m->subject,
                'attachments' => $m->attachments ?? [], 'sent_at' => $m->sent_at?->toIso8601String(),
                'sender' => AttachmentResources::author($m->sender),
            ])->values()),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'issuer' => $this->whenLoaded('issuer', fn () => AttachmentResources::author($this->issuer)),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'edit' => $this->isDraft() && (bool) $user?->can('update', $this->resource),
                'issue' => (bool) $user?->can('issue', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
