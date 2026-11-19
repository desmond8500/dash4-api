<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Task — aucun prix ; le devis lié n'est pas montré à un technicien. */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $subtasks = $this->relationLoaded('subtasks') ? $this->subtasks : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'title' => $this->title,
            'description' => $this->description,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id, 'name' => $this->project->name, 'address' => $this->project->address,
                'latitude' => $this->project->latitude !== null ? (float) $this->project->latitude : null,
                'longitude' => $this->project->longitude !== null ? (float) $this->project->longitude : null,
            ] : null),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name, 'address' => $this->client->address] : null),
            'quote_id' => $user?->isTechnician() ? null : $this->quote_id,
            'quote' => $this->when(! $user?->isTechnician() && $this->relationLoaded('quote'), fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number] : null),
            'client_request_id' => $this->client_request_id,
            'location' => $this->location,
            'starts_at' => $this->starts_at?->format('Y-m-d\TH:i:s'),
            'ends_at' => $this->ends_at?->format('Y-m-d\TH:i:s'),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map(fn ($u) => AttachmentResources::author($u))->values()),
            'subtasks' => $subtasks ? $subtasks->map(fn ($s) => $s->only(['id', 'position', 'title', 'is_done']))->values() : [],
            'subtasks_done' => $subtasks?->where('is_done', true)->count() ?? 0,
            'subtasks_count' => $subtasks?->count() ?? 0,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completer' => $this->whenLoaded('completer', fn () => AttachmentResources::author($this->completer)),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'created_by' => $this->created_by,
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'progress' => (bool) $user?->can('progress', $this->resource),
                'assign' => (bool) $user?->can('assign', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
