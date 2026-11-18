<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['amount', 'funded_at', 'method', 'note'])]
class ExpenseSpaceFunding extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'funded_at' => 'date', 'method' => PaymentMethod::class];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
