<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Entrée (`in`) ou sortie (`out`) d'argent saisie à la main : apport, emprunt, salaire, loyer, impôt… */
#[Fillable(['client_uuid', 'direction', 'label', 'amount', 'transacted_at', 'expense_category_id', 'project_id', 'client_id', 'provider_id', 'method', 'reference', 'note'])]
class Transaction extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'transacted_at' => 'date', 'method' => PaymentMethod::class];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['direction', 'label', 'amount', 'transacted_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }
}
