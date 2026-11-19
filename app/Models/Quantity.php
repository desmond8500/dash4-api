<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Quantitatif : articles et quantités par pièce, à transformer en devis. */
#[Fillable(['building_id', 'installed_system_id', 'name', 'description'])]
class Quantity extends Model
{
    use SoftDeletes;

    protected $touches = ['project'];

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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function rows(): HasMany
    {
        return $this->hasMany(QuantityRow::class)->orderBy('position')->orderBy('id');
    }
}
