<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['article_id', 'room_id', 'level_id', 'designation', 'unit', 'quantity', 'description', 'position'])]
class QuantityRow extends Model
{
    protected $touches = ['quantityList'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    /** Nommé ainsi pour ne pas masquer la colonne `quantity`. */
    public function quantityList(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'quantity_id')->withTrashed();
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }
}
