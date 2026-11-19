<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\ContractStatus;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Contrat de maintenance d'un client : forfaits facturés à chaque période (mensuelle à annuelle),
 * visites prévues par an, échéance et reconduction tacite. Chaque période devient une facture.
 */
#[Fillable([
    'client_id', 'project_id', 'title', 'status', 'start_date', 'end_date', 'tacit_renewal', 'billing_period',
    'next_invoice_date', 'visits_per_year', 'notice_days', 'description', 'terms',
])]
class Contract extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'draft', 'billing_period' => 'monthly', 'tacit_renewal' => false, 'visits_per_year' => 0, 'notice_days' => 30];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'billing_period' => BillingPeriod::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'next_invoice_date' => 'date',
            'tacit_renewal' => 'boolean',
            'end_notified_at' => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(ContractLine::class)->orderBy('position')->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('period_start');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Montant d'une période (somme des lignes). */
    public function periodAmount(): float
    {
        return round((float) $this->lines->sum(fn ($l) => $l->quantity * $l->unit_price), 2);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'status', 'start_date', 'end_date', 'billing_period', 'next_invoice_date'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('contracts');
    }
}
