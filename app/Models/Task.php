<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tâche : rattachée à un projet (et éventuellement un client, un devis, une demande),
 * affectée à des personnes, placée au planning (début, fin) avec une échéance.
 */
#[Fillable([
    'client_uuid', 'title', 'description', 'priority', 'project_id', 'client_id', 'quote_id', 'client_request_id',
    'location', 'starts_at', 'ends_at', 'due_date',
])]
class Task extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'todo', 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class)->withTrashed();
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')->withTimestamps();
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignees->contains('id', $user->id);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }

    /**
     * tasks.view : toutes. tasks.view.own : celles qui me sont affectées ou que j'ai créées,
     * et (sauf technicien) celles des projets dont je suis chef de projet.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('tasks.view')) {
            return $query;
        }
        if (! $user->can('tasks.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id));
            if (! $user->isTechnician()) {
                $q->orWhere('created_by', $user->id)
                    ->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id));
            }
        });
    }

    /** Créneau au planning qui chevauche la période. */
    public function scopeScheduledBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereNotNull('starts_at')
            ->where('starts_at', '<=', $to.' 23:59:59')
            ->whereRaw('COALESCE(ends_at, starts_at) >= ?', [$from.' 00:00:00']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'status', 'priority', 'project_id', 'starts_at', 'ends_at', 'due_date'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('tasks');
    }
}
