<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'position', 'is_visible', 'proposal'])]
class QuoteSection extends Model
{
    protected $attributes = ['position' => 0, 'is_visible' => true];

    protected $touches = ['quote'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
    }
}
