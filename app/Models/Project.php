<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'client_uuid', 'client_id', 'name', 'description', 'status', 'manager_id',
    'start_date', 'end_date', 'address', 'latitude', 'longitude', 'is_favorite',
])]
class Project extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'in_progress',
        'is_favorite' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_favorite' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** « Ses projets » : ceux dont on est chef de projet ou qu'on a créés. */
    public function isOwnedBy(User $user): bool
    {
        return $this->manager_id === $user->id || $this->created_by === $user->id;
    }

    /**
     * `projects.view` : tous ; `projects.view.own` : ses projets.
     * Technicien : les projets de ses tâches, quand les tâches existeront (rien pour l'instant).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('projects.view')) {
            return $query;
        }

        if ($user->can('projects.view.own') && ! $user->isTechnician()) {
            return $query->where(fn ($q) => $q->where('manager_id', $user->id)->orWhere('created_by', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['client_id', 'name', 'description', 'status', 'manager_id', 'start_date', 'end_date', 'address', 'is_favorite'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('projects');
    }
}
