<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            // Téléchargement avec le jeton de connexion (fichier privé)
            'download_path' => "documents/{$this->id}/download",
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
