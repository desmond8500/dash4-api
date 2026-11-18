<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['position', 'article_id', 'designation', 'reference', 'unit', 'quantity'])]
class DeliveryNoteLine extends Model
{
    protected $attributes = ['position' => 0, 'quantity' => 1];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }
}
