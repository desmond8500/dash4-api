<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relance de paiement envoyée au client (à la main ou automatiquement). */
#[Fillable(['level', 'automatic', 'sent_at'])]
class InvoiceReminder extends Model
{
    protected function casts(): array
    {
        return ['automatic' => 'boolean', 'sent_at' => 'datetime', 'level' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
