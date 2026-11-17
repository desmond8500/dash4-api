<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['firstname', 'lastname', 'email', 'phone', 'job_title', 'avatar_path', 'account_type', 'is_active', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;

    use HasRoles {
        hasPermissionTo as protected roleHasPermissionTo;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'account_type' => AccountType::class,
            'is_active' => 'boolean',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->firstname} {$this->lastname}"));
    }

    public function isTechnician(): bool
    {
        return $this->account_type === AccountType::Technician;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Permissions::ROLE_ADMIN);
    }

    /**
     * Un technicien ne peut jamais avoir certaines permissions (prix, banque…),
     * même si un rôle ou une permission directe les lui donne.
     * Toutes les vérifications (Gate, @can, $user->can()) passent par cette méthode.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->isTechnician()) {
            $name = $this->filterPermission($permission, $guardName)->name;

            if (in_array($name, Permissions::FORBIDDEN_FOR_TECHNICIANS, true)) {
                return false;
            }
        }

        return $this->roleHasPermissionTo($permission, $guardName);
    }

    /** Permissions réellement effectives (après la règle des techniciens). */
    public function effectivePermissions(): array
    {
        $names = $this->getAllPermissions()->pluck('name')->all();

        if ($this->isTechnician()) {
            $names = array_diff($names, Permissions::FORBIDDEN_FOR_TECHNICIANS);
        }

        sort($names);

        return array_values($names);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['firstname', 'lastname', 'email', 'phone', 'job_title', 'account_type', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('users');
    }
}
