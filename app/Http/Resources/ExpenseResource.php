<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'label' => $this->label,
            'description' => $this->description,
            'amount' => $this->amount,
            'spent_at' => $this->spent_at?->toDateString(),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name, 'color' => $this->category->color] : null),
            'expense_space_id' => $this->expense_space_id,
            'space' => $this->whenLoaded('space', fn () => $this->space ? ['id' => $this->space->id, 'name' => $this->space->name] : null),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'quote_id' => $this->quote_id,
            'quote' => $this->whenLoaded('quote', fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number] : null),
            'article_id' => $this->article_id,
            'article' => $this->whenLoaded('article', fn () => $this->article ? ['id' => $this->article->id, 'designation' => $this->article->designation, 'reference' => $this->article->reference] : null),
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'name' => $this->provider->name] : null),
            'payment_method' => $this->payment_method ? ['value' => $this->payment_method->value, 'label' => $this->payment_method->label()] : null,
            'advanced' => $this->advanced,
            'is_paid' => $this->is_paid,
            'to_reimburse' => $this->toReimburse(),
            'rejection_reason' => $this->rejection_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decider' => $this->whenLoaded('decider', fn () => AttachmentResources::author($this->decider)),
            'reimbursed_at' => $this->reimbursed_at?->toIso8601String(),
            'document' => $this->whenLoaded('document', fn () => $this->document ? [
                'id' => $this->document->id, 'name' => $this->document->name, 'mime_type' => $this->document->mime_type,
                'download_path' => "documents/{$this->document->id}/download",
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'created_by' => $this->created_by,
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'decide' => (bool) $user?->can('decide', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
