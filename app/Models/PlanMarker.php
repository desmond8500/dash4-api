<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Marqueur posé sur un plan (position relative de 0 à 1), lié ou non à un équipement. */
#[Fillable(['client_uuid', 'equipment_id', 'page', 'x', 'y', 'label', 'color'])]
class PlanMarker extends Model
{
    protected $touches = ['plan'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'page' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }
}
