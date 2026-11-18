<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'article_id', 'designation', 'reference', 'unit', 'quantity', 'unit_cost'])]
class PurchaseLine extends Model
{
    protected $attributes = ['position' => 0, 'quantity' => 1, 'received_quantity' => 0, 'unit_cost' => 0, 'line_total' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'received_quantity' => 'float', 'unit_cost' => 'float', 'line_total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (PurchaseLine $line) => $line->line_total = (int) round($line->unit_cost * $line->quantity));
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    public function remaining(): float
    {
        return max(0, round($this->quantity - $this->received_quantity, 2));
    }
}
