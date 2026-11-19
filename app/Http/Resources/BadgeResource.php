<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Badge */
class BadgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'badge_template_id' => $this->badge_template_id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'full_name' => $this->fullName(),
            'job_title' => $this->job_title,
            'department' => $this->department,
            'division' => $this->division,
            'employee_number' => $this->employee_number,
            'address' => $this->address,
            'valid_until' => $this->valid_until?->toDateString(),
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'printed_at' => $this->printed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
