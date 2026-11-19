<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Section d'un journal ; ses photos sont des documents rattachés à la section. */
#[Fillable(['position', 'title', 'body'])]
class JournalSection extends Model
{
    use HasAttachments;

    /** Une photo ajoutée à la section met le journal à jour (synchronisation mobile). */
    protected $touches = ['journal'];

    protected static function booted(): void
    {
        // Section retirée : ses photos partent à la corbeille avec elle
        static::deleting(fn (JournalSection $s) => $s->documents()->get()->each->delete());
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class)->withTrashed();
    }
}
