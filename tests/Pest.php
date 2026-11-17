<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

/** Crée un utilisateur actif avec le rôle donné. */
function userWithRole(string $role, array $attributes = []): User
{
    $factory = User::factory();

    if ($role === 'technician') {
        $factory = $factory->technician();
    }

    return $factory->create($attributes)->assignRole($role);
}
