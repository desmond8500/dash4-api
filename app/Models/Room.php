<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce ou local d'un niveau. */
#[Fillable(['name', 'description', 'position'])]
class Room extends Model
{
    protected $touches = ['level'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}
