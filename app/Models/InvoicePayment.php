<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['amount', 'paid_at', 'method', 'reference', 'note'])]
class InvoicePayment extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'date', 'method' => PaymentMethod::class];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['amount', 'paid_at', 'method', 'reference'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('invoices');
    }
}
