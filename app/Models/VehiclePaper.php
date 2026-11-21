<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dossier administratif d'un véhicule (assurance, carte grise, CMC, visite technique…), avec ses scans. */
#[Fillable(['type', 'label', 'number', 'provider', 'issued_on', 'expires_on', 'cost', 'notes'])]
class VehiclePaper extends Model
{
    use HasAttachments;

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'cost' => 'integer', 'alert_level' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }
}
