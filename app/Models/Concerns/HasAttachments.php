<?php

namespace App\Models\Concerns;

use App\Models\Document;
use App\Models\Link;
use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Notes, liens et documents d'une fiche. */
trait HasAttachments
{
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->orderByDesc('is_pinned')->latest()->orderByDesc('id');
    }

    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable')->orderBy('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest()->orderByDesc('id');
    }
}
