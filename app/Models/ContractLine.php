<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'un contrat : forfait (ou désignation libre), quantité, prix par période. */
#[Fillable(['flat_rate_id', 'position', 'designation', 'unit', 'quantity', 'unit_price'])]
class ContractLine extends Model
{
    protected $touches = ['contract'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }
}
