<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\BadgeTemplate */
class BadgeTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $url = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'name' => $this->name,
            'orientation' => $this->orientation,
            'primary_color' => $this->primary_color,
            'text_color' => $this->text_color,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'fields' => $this->printedFields(),
            'footer' => $this->footer,
            'logo_url' => $url($this->logo_path),
            'background_url' => $url($this->background_path),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
