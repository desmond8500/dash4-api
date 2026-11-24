<?php

namespace App\Http\Resources;

use App\Models\ToolLoan;
use App\Support\Tools;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tool — valeur d'achat seulement avec `costs.view`. */
class ToolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $today = today();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'title' => Tools::title($this->resource),
            'serial_number' => $this->serial_number,
            'article_id' => $this->article_id,
            'article' => $this->whenLoaded('article', fn () => $this->article ? [
                'id' => $this->article->id,
                'designation' => $this->article->designation,
                'reference' => $this->article->reference,
                'brand' => $this->article->relationLoaded('brand') ? $this->article->brand?->name : null,
            ] : null),
            'condition' => Tools::label(Tools::CONDITIONS, $this->condition),
            'status' => Tools::label(Tools::STATUSES, Tools::status($this->resource, $today)),
            'storage_location_id' => $this->storage_location_id,
            'storage_location' => $this->whenLoaded('storageLocation', fn () => $this->storageLocation ? ['id' => $this->storageLocation->id, 'name' => $this->storageLocation->name] : null),
            'acquired_on' => $this->acquired_on?->toDateString(),
            'purchase_value' => $this->when((bool) $user?->can('costs.view'), $this->purchase_value),
            'notes' => $this->notes,
            'current_loan' => $this->whenLoaded('currentLoan', fn () => $this->currentLoan ? self::loan($this->currentLoan, $today) : null),
            'loans' => $this->whenLoaded('loans', fn () => $this->loans->map(fn (ToolLoan $l) => self::loan($l, $today))->values()),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'lend' => (bool) $user?->can('lend', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Un prêt, tel qu'affiché sur la fiche de l'outil et dans la liste des prêts. */
    public static function loan(ToolLoan $loan, $today = null): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'full_name' => $u->full_name] : null;
        $tool = $loan->relationLoaded('tool') ? $loan->tool : null;

        return [
            'id' => $loan->id,
            'client_uuid' => $loan->client_uuid,
            'tool_id' => $loan->tool_id,
            'tool' => $tool ? ['id' => $tool->id, 'name' => $tool->name, 'code' => $tool->code, 'title' => Tools::title($tool)] : null,
            'borrower_id' => $loan->borrower_id,
            'borrower' => $person($loan->borrower),
            'borrower_name' => $loan->borrower_name,
            'borrower_phone' => $loan->borrower_phone,
            'borrower_label' => $loan->borrowerLabel(),
            'project' => $loan->project ? ['id' => $loan->project->id, 'name' => $loan->project->name] : null,
            'lent_at' => $loan->lent_at?->toIso8601String(),
            'due_on' => $loan->due_on?->toDateString(),
            'returned_at' => $loan->returned_at?->toIso8601String(),
            'return_condition' => Tools::label(Tools::CONDITIONS, $loan->return_condition),
            'notes' => $loan->notes,
            'return_notes' => $loan->return_notes,
            'state' => Tools::loanState($loan, $today),
            'last_reminded_on' => $loan->last_reminded_on?->toDateString(),
            'lender' => $person($loan->lender),
            'returned_by' => $person($loan->returner),
        ];
    }
}
