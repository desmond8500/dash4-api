<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TechAccessRequest */
class TechAccessRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'subject' => $this->subject,
            'reason' => $this->reason,
            'needed_on' => $this->needed_on?->toDateString(),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => AttachmentResources::author($this->user)),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null),
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task ? ['id' => $this->task->id, 'title' => $this->task->title] : null),
            'response' => $this->response,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decider' => $this->whenLoaded('decider', fn () => AttachmentResources::author($this->decider)),
            'tech_share_id' => $this->tech_share_id,
            'can' => [
                'decide' => (bool) $user?->can('decide', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
