<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Enums\TaxMode;
use App\Models\Concerns\HasAttachments;
use App\Support\TaxCalculator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Commande fournisseur (bon de commande BC-…). Montants hors taxes au coût d'achat. */
#[Fillable(['client_uuid', 'provider_id', 'project_id', 'title', 'expected_at', 'tax_mode', 'notes'])]
class Purchase extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $attributes = ['status' => 'draft', 'tax_mode' => 'none', 'subtotal' => 0, 'tax_amount' => 0, 'total' => 0];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'tax_mode' => TaxMode::class,
            'vat_rate' => 'float',
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'received_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('position')->orderBy('id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(PurchaseReception::class)->latest('received_at')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    /** purchases.view : tous ; purchases.view.own : ceux qu'on a créés. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->can('purchases.view') => $query,
            $user->can('purchases.view.own') => $query->where('created_by', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function recalculate(): void
    {
        $totals = TaxCalculator::totals((int) $this->lines()->sum('line_total'), $this->tax_mode === TaxMode::Vat ? TaxMode::Vat : TaxMode::None, $this->vat_rate);
        $this->forceFill(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']])->saveQuietly();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'status', 'provider_id', 'total', 'expected_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('purchases');
    }
}
