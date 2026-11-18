<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Concerns\HasPhonesAndEmails;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['client_uuid', 'name', 'type', 'status', 'description', 'address', 'is_favorite'])]
class Client extends Model
{
    use HasFactory, HasPhonesAndEmails, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'status' => ClientStatus::class,
            'is_favorite' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderBy('lastname')->orderBy('firstname');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Clients qu'un utilisateur a le droit de voir.
     * `clients.view` : tous ; `clients.view.own` (technicien) : clients de ses tâches,
     * à brancher avec le module Tâches — en attendant, aucun.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('clients.view')) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'status', 'description', 'address', 'is_favorite'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('clients');
    }
}
