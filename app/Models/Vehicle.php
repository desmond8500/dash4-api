<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Véhicule du parc (voiture, utilitaire, scooter…) : caractéristiques, conducteur attitré, papiers,
 * relevés de kilométrage, entretiens et dépenses. Le kilométrage affiché est le plus grand relevé.
 */
#[Fillable([
    'kind', 'name', 'brand', 'model', 'plate', 'vin', 'year', 'color', 'fuel', 'fiscal_power', 'seats', 'status',
    'driver_id', 'acquired_on', 'purchase_price', 'service_interval_km', 'service_interval_months', 'characteristics', 'notes',
])]
class Vehicle extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $attributes = ['kind' => 'car', 'status' => 'active', 'mileage' => 0];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'characteristics' => 'array',
            'year' => 'integer',
            'mileage' => 'integer',
            'purchase_price' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function papers(): HasMany
    {
        return $this->hasMany(VehiclePaper::class)->orderByDesc('expires_on')->orderByDesc('id');
    }

    public function mileages(): HasMany
    {
        return $this->hasMany(VehicleMileage::class)->orderByDesc('read_on')->orderByDesc('id');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class)->orderByDesc('done_on')->orderByDesc('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** Véhicules visibles : tous avec `vehicles.view`, sinon ceux dont on est le conducteur. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('vehicles.view') ? $query : $query->where('driver_id', $user->id);
    }

    /** Kilométrage = plus grand relevé, ou plus grand kilométrage d'entretien. */
    public function refreshMileage(): void
    {
        $km = max((int) $this->mileages()->max('km'), (int) $this->maintenances()->max('km'));
        $this->forceFill(['mileage' => $km])->saveQuietly();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'plate', 'status', 'driver_id'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('vehicles');
    }
}
