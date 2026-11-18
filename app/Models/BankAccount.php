<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Compte bancaire de l'entreprise ; numéro de compte et IBAN chiffrés en base. */
#[Fillable(['label', 'bank_name', 'holder', 'account_number', 'iban', 'bic', 'is_default'])]
class BankAccount extends Model
{
    use LogsActivity, SoftDeletes;

    protected $attributes = ['is_default' => false];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'iban' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Un seul compte par défaut ; le premier compte l'est automatiquement
        static::saving(function (BankAccount $account) {
            if (! $account->is_default && ! self::query()->whereKeyNot($account->id)->where('is_default', true)->exists()) {
                $account->is_default = true;
            }
        });
        static::saved(function (BankAccount $account) {
            if ($account->is_default) {
                self::query()->whereKeyNot($account->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
        static::deleted(function (BankAccount $account) {
            if ($account->is_default && $next = self::query()->oldest('id')->first()) {
                $next->update(['is_default' => true]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Jamais les numéros en clair dans le journal : seulement le fait qu'ils ont changé
        return LogOptions::defaults()
            ->logOnly(['label', 'bank_name', 'holder', 'bic', 'is_default'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('bank');
    }
}
