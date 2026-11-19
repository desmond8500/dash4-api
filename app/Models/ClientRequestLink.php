<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Lien entre une demande client et une tâche, une facture, un bordereau, un devis ou un projet. */
class ClientRequestLink extends Model
{
    protected $guarded = ['id'];

    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
