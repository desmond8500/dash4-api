<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TechShareCategory;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Information temporaire envoyée à des destinataires (codes d'accès, matériel, consignes).
 * À l'expiration (date, ou fin de la tâche), le contenu et les fichiers sont effacés : seul le titre reste.
 */
#[Fillable(['client_uuid', 'category', 'title', 'content', 'project_id', 'task_id', 'expires_at', 'expires_with_task'])]
class TechShare extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = ['category' => 'info', 'expires_with_task' => false];

    protected function casts(): array
    {
        return [
            'category' => TechShareCategory::class,
            'expires_at' => 'datetime',
            'expires_with_task' => 'boolean',
            'expired_at' => 'datetime',
        ];
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('read_at')->withTimestamps();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Expirée : effacée par la commande, ou date passée (heure du serveur) même avant son passage. */
    public function isExpired(): bool
    {
        return $this->expired_at !== null || ($this->expires_at !== null && $this->expires_at->lte(now()))
            || ($this->expires_with_task && $this->task && ! $this->task->status->isOpen());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('expired_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('expires_with_task', false)
                ->orWhereHas('task', fn ($t) => $t->whereIn('status', TaskStatus::open())));
    }

    /**
     * tech_shares.view : toutes. tech_shares.view.own : celles que j'ai créées ou reçues,
     * et (sauf technicien) celles des projets dont je suis chef de projet.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('tech_shares.view')) {
            return $query;
        }
        if (! $user->can('tech_shares.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)->orWhereHas('recipients', fn ($r) => $r->whereKey($user->id));
            if (! $user->isTechnician()) {
                $q->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id));
            }
        });
    }

    /** Efface le contenu et les fichiers ; le titre et les destinataires restent pour l'historique. */
    public function expire(): void
    {
        $this->documents()->get()->each(fn (Document $d) => $d->forceDelete());
        $this->notes()->get()->each->delete();
        $this->links()->get()->each->delete();
        $this->forceFill(['content' => null, 'expired_at' => now()])->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'category', 'expires_at', 'expired_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('tech_shares');
    }
}
