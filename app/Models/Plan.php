<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** Plan d'un chantier (image ou PDF, fichier privé) avec les marqueurs des équipements. */
#[Fillable(['building_id', 'level_id', 'name', 'position'])]
class Plan extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    protected $touches = ['project'];

    protected static function booted(): void
    {
        static::forceDeleted(fn (Plan $p) => Storage::disk(self::DISK)->delete($p->path));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function markers(): HasMany
    {
        return $this->hasMany(PlanMarker::class)->orderBy('page')->orderBy('id');
    }
}
