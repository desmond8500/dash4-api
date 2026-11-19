<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TechShare — une information expirée ne renvoie plus son contenu. */
class TechShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $expired = $this->isExpired();
        $mine = $this->relationLoaded('recipients') ? $this->recipients->firstWhere('id', $user?->id) : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'category' => ['value' => $this->category->value, 'label' => $this->category->label()],
            'title' => $this->title,
            'content' => $expired ? null : $this->content,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task ? ['id' => $this->task->id, 'title' => $this->task->title, 'status' => $this->task->status->value] : null),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_with_task' => $this->expires_with_task,
            'expired' => $expired,
            'expired_at' => $this->expired_at?->toIso8601String(),
            'recipients' => $this->whenLoaded('recipients', fn () => $this->recipients->map(fn ($u) => [
                'id' => $u->id, 'full_name' => $u->full_name, 'read_at' => $u->pivot->read_at ? \Illuminate\Support\Carbon::parse($u->pivot->read_at)->toIso8601String() : null,
            ])->values()),
            'is_recipient' => $mine !== null,
            'read_at' => $mine?->pivot->read_at ? \Illuminate\Support\Carbon::parse($mine->pivot->read_at)->toIso8601String() : null,
            'files' => $expired ? [] : $this->whenLoaded('documents', fn () => DocumentResource::collection($this->documents)->resolve($request)),
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
