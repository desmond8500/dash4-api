<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Journal — le devis lié n'est pas montré à un technicien. */
class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $tech = (bool) $user?->isTechnician();

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'title' => $this->title,
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'date' => $this->date?->toDateString(),
            'starts_at' => $this->starts_at?->format('Y-m-d\TH:i:s'),
            'ends_at' => $this->ends_at?->format('Y-m-d\TH:i:s'),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'quote_id' => $tech ? null : $this->quote_id,
            'quote' => $this->when(! $tech && $this->relationLoaded('quote'), fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number] : null),
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task ? ['id' => $this->task->id, 'title' => $this->task->title] : null),
            'summary' => $this->summary,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($s) => $s->only(['id', 'position', 'title', 'body']))->values()),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'contact_id' => $p->contact_id,
                'name' => $p->displayName(),
                'role' => $p->role,
                'kind' => $p->user_id ? 'team' : ($p->contact_id ? 'contact' : 'other'),
            ])->values()),
            'photos_count' => $this->whenCounted('documents'),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'created_by' => $this->created_by,
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
