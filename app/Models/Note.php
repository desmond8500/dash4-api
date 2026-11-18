<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Note rattachée à n'importe quelle fiche (projet, client…). */
#[Fillable(['client_uuid', 'body', 'is_pinned'])]
class Note extends Model
{
    use SoftDeletes;

    protected $attributes = ['is_pinned' => false];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
