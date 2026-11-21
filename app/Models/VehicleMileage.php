<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relevé du compteur d'un véhicule. */
#[Fillable(['client_uuid', 'read_on', 'km', 'note'])]
class VehicleMileage extends Model
{
    protected function casts(): array
    {
        return ['read_on' => 'date', 'km' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
