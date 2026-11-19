<?php

namespace App\Models;

use App\Enums\AccessRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Demande d'accès d'un technicien (codes, clés, badge…) ; acceptée, elle crée une information temporaire. */
#[Fillable(['client_uuid', 'subject', 'reason', 'project_id', 'task_id', 'needed_on'])]
class TechAccessRequest extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'needed_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(TechShare::class, 'tech_share_id')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === AccessRequestStatus::Pending;
    }

    /**
     * tech_access_requests.view : toutes. view.own : les miennes, et pour qui décide (chef de projet)
     * celles des projets dont il est chef de projet.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('tech_access_requests.view')) {
            return $query;
        }
        if (! $user->can('tech_access_requests.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if (! $user->isTechnician() && $user->can('tech_access_requests.decide')) {
                $q->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['subject', 'status', 'decided_by'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('tech_access_requests');
    }
}
