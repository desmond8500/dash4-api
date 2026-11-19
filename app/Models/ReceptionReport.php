<?php

namespace App\Models;

use App\Enums\ReceptionStatus;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Procès-verbal de réception : parties (client, maître d'ouvrage, maître d'œuvre, bureau de contrôle)
 * avec leur logo, systèmes réceptionnés, réserves et leur levée. La copie signée est jointe en document.
 */
#[Fillable([
    'quote_id', 'title', 'subtitle', 'date', 'status', 'location', 'client_name', 'owner_name', 'engineer_name',
    'inspector_name', 'observations', 'reserves',
])]
class ReceptionReport extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    public const PARTIES = ['client' => 'Client', 'owner' => "Maître d'ouvrage", 'engineer' => "Maître d'œuvre", 'inspector' => 'Bureau de contrôle'];

    protected $attributes = ['status' => 'draft'];

    protected $touches = ['project'];

    protected function casts(): array
    {
        return ['date' => 'date', 'status' => ReceptionStatus::class, 'reserves' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(InstalledSystem::class)->orderBy('position')->orderBy('installed_systems.id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'date', 'status'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('sites');
    }
}
