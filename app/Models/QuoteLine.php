<?php

namespace App\Models;

use App\Enums\QuoteLineCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne de devis : prix unitaire = coût × coefficient, arrondi au franc. */
#[Fillable(['position', 'article_id', 'designation', 'reference', 'comment', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient'])]
class QuoteLine extends Model
{
    protected $attributes = [
        'position' => 0,
        'category' => 'other',
        'quantity' => 1,
        'unit_cost' => 0,
        'coefficient' => 1,
        'unit_price' => 0,
        'line_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => QuoteLineCategory::class,
            'quantity' => 'float',
            'unit_cost' => 'float',
            'coefficient' => 'float',
            'unit_price' => 'integer',
            'line_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (QuoteLine $line) {
            $line->unit_price = (int) round($line->unit_cost * $line->coefficient);
            $line->line_total = (int) round($line->unit_price * $line->quantity);
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(QuoteSection::class, 'quote_section_id');
    }
}
