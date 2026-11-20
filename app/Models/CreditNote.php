<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Avoir : annule tout ou partie d'une facture émise ; numéroté AV-…, jamais modifié ni supprimé. */
#[Fillable(['issued_at', 'reason', 'amount'])]
class CreditNote extends Model
{
    protected function casts(): array
    {
        return ['issued_at' => 'date', 'amount' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
