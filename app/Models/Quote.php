<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Enums\TaxMode;
use App\Models\Concerns\HasAttachments;
use App\Support\TaxCalculator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'client_uuid', 'client_id', 'project_id', 'title', 'introduction', 'payment_terms', 'notes',
    'issued_at', 'valid_until', 'tax_mode', 'vat_rate', 'brs_rate', 'discount_rate',
    'bank_account_id', 'show_signature', 'is_favorite',
])]
class Quote extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'tax_mode' => 'vat',
        'vat_rate' => 18,
        'brs_rate' => 5,
        'discount_rate' => 0,
        'show_signature' => true,
        'is_favorite' => false,
        'subtotal' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'cost_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'tax_mode' => TaxMode::class,
            'issued_at' => 'date',
            'valid_until' => 'date',
            'vat_rate' => 'float',
            'brs_rate' => 'float',
            'discount_rate' => 'float',
            'cost_total' => 'float',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'show_signature' => 'boolean',
            'is_favorite' => 'boolean',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(QuoteSection::class)->orderBy('position')->orderBy('id');
    }

    public function lines(): HasManyThrough
    {
        return $this->hasManyThrough(QuoteLine::class, QuoteSection::class);
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }

    /** « Ses devis » : ceux qu'on a créés, ou ceux des projets dont on est chef de projet. */
    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id
            || ($this->project_id !== null && $this->project?->manager_id === $user->id);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('quotes.view')) {
            return $query;
        }

        if ($user->can('quotes.view.own') && ! $user->isTechnician()) {
            return $query->where(fn ($q) => $q->where('created_by', $user->id)
                ->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id)));
        }

        return $query->whereRaw('1 = 0');
    }

    /** Recalcule et enregistre les totaux à partir des lignes des sections visibles. */
    public function recalculate(): void
    {
        $lines = QuoteLine::query()
            ->whereIn('quote_section_id', $this->sections()->where('is_visible', true)->select('id'))
            ->get(['quantity', 'unit_cost', 'line_total']);

        $subtotal = (int) $lines->sum('line_total');
        $discount = (int) round($subtotal * $this->discount_rate / 100);
        $totals = TaxCalculator::totals($subtotal - $discount, $this->tax_mode, $this->vat_rate, $this->brs_rate);

        $this->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $totals['tax'],
            'total' => $totals['total'],
            'cost_total' => round($lines->sum(fn ($l) => (float) $l->unit_cost * (float) $l->quantity), 2),
        ])->saveQuietly();
        $this->touch();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'client_id', 'project_id', 'title', 'status', 'tax_mode', 'discount_rate', 'total'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('quotes');
    }
}
