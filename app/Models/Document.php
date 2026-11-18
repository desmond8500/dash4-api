<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Fichier rattaché à une fiche, stocké sur le disque privé (storage/app/private/documents) :
 * jamais servi directement, seulement par /api/v1/documents/{id}/download après contrôle des droits.
 */
class Document extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    protected $fillable = ['name', 'path', 'mime_type', 'size'];

    protected static function booted(): void
    {
        // Le fichier n'est effacé qu'à la suppression définitive (la corbeille le garde)
        static::forceDeleted(fn (Document $d) => Storage::disk(self::DISK)->delete($d->path));
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
