<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Icône de la bibliothèque des plans : un symbole intégré (dessiné par l'application, colorable) ou une
 * image envoyée. Un article peut avoir son icône : ses équipements la prennent sur les plans.
 */
#[Fillable(['name', 'category', 'symbol', 'color'])]
class PlanIcon extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    /** Symboles intégrés (même liste que `PLAN_SYMBOLS` de @dash4/shared). */
    public const SYMBOLS = [
        'dot', 'square', 'triangle', 'diamond', 'star', 'arrow', 'camera', 'dome', 'smoke', 'heat', 'motion', 'contact', 'siren',
        'keypad', 'call_point', 'reader', 'door', 'speaker', 'wifi', 'panel', 'light', 'socket', 'extinguisher', 'sprinkler',
    ];

    public function articles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Article::class);
    }

    protected static function booted(): void
    {
        static::forceDeleted(fn (PlanIcon $icon) => $icon->path && Storage::disk(self::DISK)->delete($icon->path));
    }

}
