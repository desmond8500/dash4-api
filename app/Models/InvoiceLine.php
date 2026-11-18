<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Ligne de facture (prix de vente figé ; négatif pour une remise ou une déduction). */
#[Fillable(['position', 'designation', 'reference', 'unit', 'quantity', 'unit_price'])]
class InvoiceLine extends Model
{
    protected $attributes = ['position' => 0, 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'integer', 'line_total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (InvoiceLine $line) => $line->line_total = (int) round($line->unit_price * $line->quantity));
    }
}
