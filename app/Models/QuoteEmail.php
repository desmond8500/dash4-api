<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace d'un envoi de devis par e-mail. */
class QuoteEmail extends Model
{
    protected $fillable = ['to', 'cc', 'subject', 'message', 'attachments', 'sent_at'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'attachments' => 'array', 'sent_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
