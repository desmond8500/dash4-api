<?php

namespace App\Http\Resources;

use App\Models\Cv;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Cv */
class CvResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'title' => $this->title,
            'fullname' => $this->fullname,
            'summary' => $this->summary,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'website' => $this->website,
            'accent_color' => $this->accent_color,
            'font' => $this->font,
            'layout' => $this->layout,
            'show_photo' => $this->show_photo,
            'shared' => $this->shared,
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'type_label' => Cv::SECTION_TYPES[$s->type] ?? $s->type,
                'items' => $s->items->map(fn ($i) => $i->only(['id', 'title', 'subtitle', 'place', 'start_date', 'end_date', 'current', 'description', 'level']))->values(),
            ])->values()),
            'can' => ['update' => $request->user()?->id === $this->user_id],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
