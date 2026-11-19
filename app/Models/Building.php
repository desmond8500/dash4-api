<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bâtiment d'un projet, avec ses niveaux et leurs pièces. */
#[Fillable(['name', 'description', 'position'])]
class Building extends Model
{
    use HasAttachments, SoftDeletes;

    protected $touches = ['project'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function levels(): HasMany
    {
        return $this->hasMany(Level::class)->orderBy('position')->orderBy('id');
    }
}
