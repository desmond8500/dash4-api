<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** Plan d'un chantier (image ou PDF, fichier privé) avec les marqueurs des équipements. */
#[Fillable(['building_id', 'level_id', 'name', 'position', 'settings'])]
class Plan extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    protected $touches = ['project'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'renders' => 'array'];
    }

    /** Réglages : calques masqués (identifiants de systèmes, `none` = sans système), légende affichée. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /** Aperçu d'une page (image avec repères et légende, faite par l'éditeur) : chemin sur le disque privé. */
    public function renderPath(int $page): ?string
    {
        $path = $this->renders[(string) $page]['path'] ?? null;

        return $path && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (Plan $p) {
            Storage::disk(self::DISK)->delete([$p->path, ...array_filter(array_column($p->renders ?? [], 'path'))]);
        });
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
