<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Marqueur posé sur un plan (position relative de 0 à 1), lié ou non à un équipement. */
#[Fillable(['client_uuid', 'equipment_id', 'plan_icon_id', 'installed_system_id', 'page', 'x', 'y', 'size', 'rotation', 'label', 'show_label', 'color'])]
class PlanMarker extends Model
{
    protected $touches = ['plan'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'page' => 'integer', 'size' => 'float', 'rotation' => 'integer', 'show_label' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function icon(): BelongsTo
    {
        return $this->belongsTo(PlanIcon::class, 'plan_icon_id')->withTrashed();
    }

    public function installedSystem(): BelongsTo
    {
        return $this->belongsTo(InstalledSystem::class)->withTrashed();
    }

    /** Icône affichée : celle du repère, sinon celle de l'article de l'équipement. */
    public function effectiveIconId(): ?int
    {
        return $this->plan_icon_id ?? $this->equipment?->article?->plan_icon_id;
    }

    /** Calque : le système choisi, sinon celui de l'équipement. */
    public function layerId(): ?int
    {
        return $this->installed_system_id ?? $this->equipment?->installed_system_id;
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }
}
