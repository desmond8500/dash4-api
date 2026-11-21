<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Dépense (Dash3 : expenses et invoice_spents). Montant en francs CFA.
 * Saisie par le comptable : validée tout de suite ; par les autres : à valider.
 */
#[Fillable([
    'client_uuid', 'label', 'description', 'amount', 'spent_at', 'expense_category_id', 'expense_space_id',
    'project_id', 'quote_id', 'provider_id', 'article_id', 'vehicle_id', 'payment_method', 'advanced', 'is_paid',
])]
class Expense extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'submitted', 'advanced' => false, 'is_paid' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'spent_at' => 'date',
            'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class,
            'advanced' => 'boolean',
            'is_paid' => 'boolean',
            'decided_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(ExpenseSpace::class, 'expense_space_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function reimburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reimbursed_by');
    }

    public function isSubmitted(): bool
    {
        return $this->status === ExpenseStatus::Submitted;
    }

    /** À rembourser : avancée par la personne, validée, pas encore remboursée. */
    public function toReimburse(): bool
    {
        return $this->advanced && $this->status === ExpenseStatus::Approved;
    }

    /** accounting.view : toutes ; sinon les siennes et celles des caisses dont on est membre. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('accounting.view')) {
            return $query;
        }
        if (! $user->can('accounting.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($q) => $q->where('created_by', $user->id)
            ->orWhereIn('expense_space_id', ExpenseSpace::query()->whereHas('members', fn ($m) => $m->whereKey($user->id))->select('id')));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['label', 'amount', 'spent_at', 'status', 'expense_category_id', 'project_id'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }
}
