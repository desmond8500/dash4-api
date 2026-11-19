<?php

namespace App\Models;

use App\Enums\JournalType;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Journal (rapport) d'intervention : sections, intervenants, photos, position. */
#[Fillable([
    'client_uuid', 'title', 'type', 'date', 'starts_at', 'ends_at', 'project_id', 'client_id', 'quote_id', 'task_id',
    'summary', 'latitude', 'longitude',
])]
class Journal extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = ['type' => 'intervention'];

    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(JournalSection::class)->orderBy('position')->orderBy('id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(JournalParticipant::class)->orderBy('id');
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function involves(User $user): bool
    {
        return $this->created_by === $user->id || $this->participants->contains('user_id', $user->id);
    }

    /**
     * journals.view : tous. journals.view.own : ceux que j'ai écrits ou où j'interviens,
     * et (sauf technicien) ceux des projets dont je suis chef de projet.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('journals.view')) {
            return $query;
        }
        if (! $user->can('journals.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));
            if (! $user->isTechnician()) {
                $q->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'type', 'date', 'project_id'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('journals');
    }
}
