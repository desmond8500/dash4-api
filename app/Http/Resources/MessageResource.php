<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deleted = $this->deleted_at !== null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'author' => $this->whenLoaded('author', fn () => AttachmentResources::author($this->author)),
            'body' => $deleted ? null : $this->body,
            'files' => $deleted ? [] : $this->whenLoaded('documents', fn () => DocumentResource::collection($this->documents)->resolve($request)),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can' => ['update' => ! $deleted && $request->user()?->id === $this->user_id],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
