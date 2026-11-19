<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Badge d'une personne pour un projet (contrôle d'accès, identification du personnel). */
#[Fillable(['badge_template_id', 'firstname', 'lastname', 'job_title', 'department', 'division', 'employee_number', 'address', 'valid_until'])]
class Badge extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'printed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BadgeTemplate::class, 'badge_template_id')->withTrashed();
    }

    public function fullName(): string
    {
        return trim(($this->firstname ?? '').' '.($this->lastname ?? ''));
    }
}
