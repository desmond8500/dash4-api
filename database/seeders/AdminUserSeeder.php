<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Crée le premier administrateur à partir du .env (ADMIN_EMAIL, ADMIN_PASSWORD…).
 * Ne fait rien si ADMIN_EMAIL est vide ou si le compte existe déjà.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('dash4.admin.email');
        $password = config('dash4.admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD vides : aucun administrateur créé.');

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'firstname' => config('dash4.admin.firstname'),
                'lastname' => config('dash4.admin.lastname'),
                'account_type' => AccountType::Manager,
                'is_active' => true,
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(Permissions::ROLE_ADMIN);
    }
}
