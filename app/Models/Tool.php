<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Outil (un exemplaire physique : perceuse n° OUT-012…), lié à un article du stock.
 * Il est disponible, prêté (un prêt non rendu), ou indisponible selon son état (en réparation, perdu, réformé).
 */
#[Fillable(['article_id', 'name', 'code', 'serial_number', 'condition', 'storage_location_id', 'acquired_on', 'purchase_value', 'notes'])]
class Tool extends Model
{
    use HasAttachments, LogsActivity, SoftDeletes;

    protected $attributes = ['condition' => 'good'];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'purchase_value' => 'integer',
        ];
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->photo_path) : null;
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    public function loans(): HasMany
    {
        return $this->hasMany(ToolLoan::class)->orderByDesc('lent_at')->orderByDesc('id');
    }

    /** Prêt en cours (non rendu) : un seul à la fois. */
    public function currentLoan(): HasOne
    {
        return $this->hasOne(ToolLoan::class)->whereNull('returned_at')->latest('lent_at');
    }

    /** Outils visibles : tous avec `tools.view`, sinon ceux qu'on a actuellement en prêt. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('tools.view')
            ? $query
            : $query->whereHas('loans', fn ($q) => $q->whereNull('returned_at')->where('borrower_id', $user->id));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'code', 'condition', 'article_id'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('tools');
    }
}
