<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Acompte demandé au client sur un devis. */
#[Fillable(['label', 'description', 'amount', 'due_date', 'is_paid', 'paid_at', 'show_on_pdf'])]
class QuoteDeposit extends Model
{
    use LogsActivity, SoftDeletes;

    protected $attributes = ['is_paid' => false, 'show_on_pdf' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'due_date' => 'date',
            'paid_at' => 'date',
            'is_paid' => 'boolean',
            'show_on_pdf' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Payé sans date : aujourd'hui ; plus payé : pas de date
        static::saving(function (QuoteDeposit $deposit) {
            if ($deposit->is_paid) {
                $deposit->paid_at ??= now()->toDateString();
            } else {
                $deposit->paid_at = null;
            }
        });
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['label', 'amount', 'due_date', 'is_paid', 'paid_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('quotes');
    }

    /** Factures émises pour cet acompte. */
    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invoice::class, 'quote_deposit_id');
    }
}
