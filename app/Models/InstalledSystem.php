<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Système installé sur un projet (dans un bâtiment ou non), avec ses équipements et son avancement. */
#[Fillable(['building_id', 'system_id', 'name', 'description', 'position'])]
class InstalledSystem extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $touches = ['project'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class)->withTrashed();
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class)->orderBy('position')->orderBy('id');
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(SystemProgress::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'building_id', 'progress'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('sites');
    }
}
