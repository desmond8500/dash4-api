<?php

namespace App\Models;

use App\Enums\ClientRequestSource;
use App\Enums\ClientRequestStatus;
use App\Enums\Priority;
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
    'client_uuid', 'body', 'client_id', 'contact_name', 'contact_phone', 'contact_email',
    'source', 'status', 'priority', 'assigned_to', 'remind_at',
])]
class ClientRequest extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'source' => 'phone',
        'status' => 'new',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'source' => ClientRequestSource::class,
            'status' => ClientRequestStatus::class,
            'priority' => Priority::class,
            'remind_at' => 'datetime',
            'processed_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'reminder_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ClientRequest $request) {
            // Date de traitement : remplie à la clôture, effacée si la demande est rouverte
            if ($request->isDirty('status')) {
                if ($request->status->isClosed()) {
                    $request->processed_at ??= now();
                } else {
                    $request->processed_at = null;
                    $request->processed_by = null;
                }
            }

            // Nouvelle date de rappel : le rappel sera envoyé de nouveau
            if ($request->isDirty('remind_at')) {
                $request->reminder_notified_at = null;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Délai après lequel une demande encore « Nouvelle » est en retard (2 jours par défaut). */
    public static function overdueDays(): int
    {
        return max(1, (int) config('dash4.client_requests.overdue_days', 2));
    }

    public function isOverdue(): bool
    {
        return $this->status === ClientRequestStatus::New
            && $this->created_at !== null
            && $this->created_at->lte(now()->subDays(self::overdueDays()));
    }

    /**
     * `client_requests.view` : toutes ; `client_requests.view.own` (technicien) :
     * celles qu'il a créées ou qui lui sont confiées.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('client_requests.view')) {
            return $query;
        }

        if ($user->can('client_requests.view.own')) {
            return $query->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('assigned_to', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ClientRequestStatus::open());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ClientRequestStatus::New->value)
            ->where('created_at', '<=', now()->subDays(self::overdueDays()));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['body', 'client_id', 'contact_name', 'contact_phone', 'source', 'status', 'priority', 'assigned_to', 'remind_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('client_requests');
    }

    public function links(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientRequestLink::class)->latest('id');
    }

    public function inboundEmail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InboundEmail::class);
    }
}
