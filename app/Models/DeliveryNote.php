<?php

namespace App\Models;

use App\Enums\DeliveryNoteType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Bordereau de livraison ou de travaux d'un devis, sans prix. */
#[Fillable(['type', 'title', 'delivered_at', 'done', 'todo', 'show_logo'])]
class DeliveryNote extends Model
{
    use LogsActivity, SoftDeletes;

    protected $attributes = ['type' => 'delivery', 'show_logo' => true];

    protected function casts(): array
    {
        return ['type' => DeliveryNoteType::class, 'delivered_at' => 'date', 'show_logo' => 'boolean'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('position')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'type', 'title', 'delivered_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('quotes');
    }
}
