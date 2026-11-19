<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace d'un envoi par e-mail : devis, facture ou rapport d'intervention. */
class QuoteEmail extends Model
{
    protected $fillable = ['to', 'cc', 'subject', 'message', 'attachments', 'sent_at'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'attachments' => 'array', 'sent_at' => 'datetime'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
