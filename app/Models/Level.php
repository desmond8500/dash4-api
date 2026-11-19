<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Niveau d'un bâtiment (sous-sol, RDC, R+1…). */
#[Fillable(['name', 'description', 'position'])]
class Level extends Model
{
    protected $touches = ['building'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('position')->orderBy('id');
    }
}
