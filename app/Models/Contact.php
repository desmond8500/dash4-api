<?php

namespace App\Models;

use App\Enums\Civility;
use App\Models\Concerns\HasPhonesAndEmails;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['client_uuid', 'client_id', 'civility', 'firstname', 'lastname', 'job_title', 'company', 'email', 'phone', 'notes'])]
class Contact extends Model
{
    use HasFactory, HasPhonesAndEmails, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'civility' => Civility::class,
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->firstname} {$this->lastname}"));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Contacts visibles : ceux des clients visibles, plus les contacts sans client pour `clients.view`. */
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
            ->logOnly(['client_id', 'civility', 'firstname', 'lastname', 'job_title', 'company', 'email', 'phone'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('contacts');
    }
}
