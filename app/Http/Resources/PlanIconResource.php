<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PlanIcon — l'image se télécharge par `file_path` (jeton requis). */
class PlanIconResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'slug' => $this->slug,
            'symbol' => $this->symbol,
            'code' => $this->code,
            'color' => $this->color,
            'text_color' => $this->text_color,
            'secondary_color' => $this->secondary_color,
            'position' => $this->position,
            'file_path' => $this->path ? "plan-icons/{$this->id}/file" : null,
            'mime' => $this->mime,
            'articles_count' => $this->articles_count ?? null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
