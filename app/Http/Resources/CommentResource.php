<?php

namespace App\Http\Resources;

use App\Support\Attachable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Comment */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $parent = $this->relationLoaded('commentable') ? $this->commentable : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => AttachmentResources::author($this->author)),
            'created_by' => $this->created_by,
            'mentions' => $this->whenLoaded('mentions', fn () => $this->mentions->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name])->values()),
            'files' => $this->whenLoaded('documents', fn () => DocumentResource::collection($this->documents)->resolve($request)),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can' => [
                'update' => $user !== null && $this->created_by === $user->id,
                'delete' => $user !== null && $parent !== null && Attachable::canDeleteComment($user, $this->resource, $parent),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
