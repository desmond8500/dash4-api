<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Caisse (espace de dépenses) : des versements l'alimentent, les dépenses de ses membres
 * payées avec l'argent de la caisse (non avancées) la diminuent.
 */
#[Fillable(['name', 'description', 'is_active'])]
class ExpenseSpace extends Model
{
    use LogsActivity, SoftDeletes;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'expense_space_members')->withTimestamps();
    }

    public function fundings(): HasMany
    {
        return $this->hasMany(ExpenseSpaceFunding::class)->latest('funded_at')->orderByDesc('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return array{funded: int, spent: int, pending: int, balance: int} */
    public function balance(): array
    {
        $funded = (int) $this->fundings()->sum('amount');
        $fromCash = $this->expenses()->where('advanced', false)->where('status', '!=', ExpenseStatus::Rejected->value);
        $spent = (int) (clone $fromCash)->sum('amount');
        $pending = (int) (clone $fromCash)->where('status', ExpenseStatus::Submitted->value)->sum('amount');

        return ['funded' => $funded, 'spent' => $spent, 'pending' => $pending, 'balance' => $funded - $spent];
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /** accounting.view : toutes ; sinon celles dont on est membre. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('accounting.view') ? $query : $query->whereHas('members', fn ($m) => $m->whereKey($user->id));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }
}
