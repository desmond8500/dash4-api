<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Fiche technique (zones d'alarme, détecteurs, lecteurs…) d'un bâtiment ou d'un système. */
#[Fillable(['building_id', 'installed_system_id', 'title', 'type', 'date', 'contact_name', 'contact_phone', 'contact_email', 'notes'])]
class Sheet extends Model
{
    use HasAttachments, SoftDeletes;

    protected $touches = ['project'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function installedSystem(): BelongsTo
    {
        return $this->belongsTo(InstalledSystem::class)->withTrashed();
    }

    public function zones(): HasMany
    {
        return $this->hasMany(SheetZone::class)->orderBy('position')->orderBy('id');
    }
}
