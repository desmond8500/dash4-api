<?php

namespace App\Models;

use App\Enums\QuoteLineCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'article_id', 'designation', 'reference', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient'])]
class QuoteTemplateLine extends Model
{
    protected $attributes = ['position' => 0, 'category' => 'other', 'quantity' => 1, 'unit_cost' => 0, 'coefficient' => 1];

    protected function casts(): array
    {
        return [
            'category' => QuoteLineCategory::class,
            'quantity' => 'float',
            'unit_cost' => 'float',
            'coefficient' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    /** Champs copiés dans une ligne de devis. */
    public function toQuoteLine(): array
    {
        return $this->only(['article_id', 'designation', 'reference', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient']);
    }
}
