<?php

namespace App\Models;

use App\Enums\InvoiceScope;
use App\Enums\InvoiceStatus;
use App\Enums\TaxMode;
use App\Models\Concerns\HasAttachments;
use App\Support\TaxCalculator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'client_uuid', 'client_id', 'project_id', 'title', 'introduction', 'payment_terms', 'notes',
    'due_at', 'tax_mode', 'bank_account_id', 'show_signature',
])]
class Invoice extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'scope' => 'all',
        'percent' => 100,
        'tax_mode' => 'none',
        'vat_rate' => 18,
        'brs_rate' => 5,
        'show_signature' => true,
        'subtotal' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'paid_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'scope' => InvoiceScope::class,
            'tax_mode' => TaxMode::class,
            'percent' => 'float',
            'vat_rate' => 'float',
            'brs_rate' => 'float',
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'show_signature' => 'boolean',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(QuoteDeposit::class, 'quote_deposit_id')->withTrashed();
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

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(QuoteEmail::class)->latest('sent_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /** Net hors taxes (lignes, remise et déductions comprises). */
    public function netAmount(): int
    {
        return $this->subtotal;
    }

    public function remainingAmount(): int
    {
        return max(0, $this->total - $this->paid_amount);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
            && $this->due_at !== null && $this->due_at->isPast() && ! $this->due_at->isToday();
    }

    /** `invoices.view` : toutes (le technicien n'a pas ce droit). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('invoices.view') && ! $user->isTechnician() ? $query : $query->whereRaw('1 = 0');
    }

    /** Totaux à partir des lignes (TVA ajoutée, BRS déduite, ou aucune). */
    public function recalculate(): void
    {
        $subtotal = (int) $this->lines()->sum('line_total');
        $totals = TaxCalculator::totals($subtotal, $this->tax_mode, $this->vat_rate, $this->brs_rate);
        $this->forceFill(['subtotal' => $subtotal, 'tax_amount' => $totals['tax'], 'total' => $totals['total']])->saveQuietly();
        $this->refreshPayments();
    }

    /** Montant payé et statut d'après les paiements (sur une facture émise). */
    public function refreshPayments(): void
    {
        $paid = (int) $this->payments()->sum('amount');
        $this->paid_amount = $paid;

        if (in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            $this->status = match (true) {
                $paid >= $this->total && $this->total > 0 => InvoiceStatus::Paid,
                $paid > 0 => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Issued,
            };
            $this->paid_at = $this->status === InvoiceStatus::Paid
                ? ($this->paid_at ?? $this->payments()->max('paid_at') ?? now())
                : null;
        }
        $this->saveQuietly();
        $this->touch();

        // Facture d'un acompte prévu sur le devis : l'acompte suit son paiement
        if ($this->quote_deposit_id && $deposit = $this->deposit) {
            $isPaid = $this->status === InvoiceStatus::Paid;
            if ($deposit->is_paid !== $isPaid) {
                $deposit->update(['is_paid' => $isPaid, 'paid_at' => $isPaid ? $this->payments()->max('paid_at') : null]);
            }
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'title', 'status', 'issued_at', 'due_at', 'tax_mode', 'total', 'paid_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('invoices');
    }
}
