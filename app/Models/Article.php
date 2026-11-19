<?php

namespace App\Models;

use App\Enums\QuoteLineCategory;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Article du stock (un seul magasin en V1). Prix de vente = coût × coefficient, comme les lignes de devis.
 * La quantité ne change que par un mouvement (App\Support\Stock).
 */
#[Fillable([
    'client_uuid', 'designation', 'reference', 'barcode', 'description', 'category', 'unit', 'brand_id', 'provider_id',
    'unit_cost', 'coefficient', 'min_quantity', 'storage_location_id', 'weight', 'is_active',
])]
class Article extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'category' => 'other',
        'unit_cost' => 0,
        'coefficient' => 1,
        'quantity' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'category' => QuoteLineCategory::class,
            'unit_cost' => 'float',
            'coefficient' => 'float',
            'quantity' => 'float',
            'min_quantity' => 'float',
            'weight' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'article_dependencies', 'article_id', 'dependency_id')
            ->withPivot('quantity')->withTimestamps();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('moved_at')->orderByDesc('id');
    }

    public function unitPrice(): int
    {
        return (int) round($this->unit_cost * $this->coefficient);
    }

    /** Sous le seuil (ou à zéro avec un seuil fixé). */
    public function isLow(): bool
    {
        return $this->min_quantity !== null && $this->quantity <= $this->min_quantity;
    }

    public function scopeLow(Builder $query): Builder
    {
        return $query->whereNotNull('min_quantity')->whereColumn('quantity', '<=', 'min_quantity');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['designation', 'reference', 'unit_cost', 'coefficient', 'min_quantity', 'is_active'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('stock');
    }
}
