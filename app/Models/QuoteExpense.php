<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Dépense réelle liée à un devis (coût engagé) : réservée à costs.view. */
#[Fillable(['label', 'description', 'amount', 'spent_at', 'is_paid'])]
class QuoteExpense extends Model
{
    use LogsActivity, SoftDeletes;

    protected $attributes = ['is_paid' => true];

    protected function casts(): array
    {
        return ['amount' => 'float', 'spent_at' => 'date', 'is_paid' => 'boolean'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['label', 'amount', 'spent_at', 'is_paid'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('quotes');
    }
}
