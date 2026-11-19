<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @mixin \App\Models\Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $members = $this->relationLoaded('members') ? $this->members : collect();
        $others = $members->reject(fn ($m) => $m->id === $user?->id);
        $last = $this->relationLoaded('lastMessage') ? $this->lastMessage->first() : null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            // Conversation privée : le nom de l'autre personne
            'title' => $this->type === 'direct' ? ($others->first()?->full_name ?? 'Conversation') : $this->title,
            'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null,
            'task' => $this->task ? ['id' => $this->task->id, 'title' => $this->task->title] : null,
            'members' => $members->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'job_title' => $m->job_title,
                'avatar_url' => $m->avatar_path ? Storage::disk('public')->url($m->avatar_path) : null,
                'last_read_message_id' => $m->pivot?->last_read_message_id,
            ])->values(),
            'last_message' => $last ? [
                'id' => $last->id,
                'excerpt' => $last->deleted_at ? 'Message supprimé' : ($last->body ? Str::limit($last->body, 80) : 'Fichier'),
                'author' => $last->author ? ['id' => $last->author->id, 'full_name' => $last->author->full_name] : null,
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'can' => ['manage' => $user !== null && $user->can('manage', $this->resource)],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
