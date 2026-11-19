<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relevé d'avancement d'un système installé (date, pourcentage, note). */
#[Fillable(['client_uuid', 'date', 'percent', 'note'])]
class SystemProgress extends Model
{
    protected $table = 'system_progress';

    protected function casts(): array
    {
        return ['date' => 'date', 'percent' => 'integer'];
    }

    public function installedSystem(): BelongsTo
    {
        return $this->belongsTo(InstalledSystem::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
