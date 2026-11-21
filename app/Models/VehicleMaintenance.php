<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entretien fait sur un véhicule (vidange, révision, pneus…), avec le prochain prévu (date et/ou kilométrage). */
#[Fillable(['type', 'done_on', 'km', 'description', 'garage', 'cost', 'next_on', 'next_km'])]
class VehicleMaintenance extends Model
{
    protected function casts(): array
    {
        return ['done_on' => 'date', 'next_on' => 'date', 'km' => 'integer', 'next_km' => 'integer', 'cost' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
