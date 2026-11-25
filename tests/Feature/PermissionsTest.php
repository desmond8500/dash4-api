<?php

use App\Support\Permissions;
use Spatie\Permission\Models\Role;

it('crée les 7 profils', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(collect(array_keys(Permissions::ROLE_LABELS))->sort()->values()->all());
});

it('ne donne jamais les prix à un technicien, même avec un rôle qui les contient', function () {
    $technician = userWithRole('technician');
    expect($technician->can('prices.view'))->toBeFalse();

    // Même si quelqu'un lui ajoute par erreur le rôle Administrateur ou la permission directement
    $technician->assignRole('admin');
    $technician->givePermissionTo('prices.view', 'costs.view');

    expect($technician->fresh()->can('prices.view'))->toBeFalse()
        ->and($technician->fresh()->can('costs.view'))->toBeFalse()
        ->and($technician->fresh()->effectivePermissions())->not->toContain('prices.view');
});

it('donne au technicien uniquement son espace', function () {
    $technician = userWithRole('technician');

    expect($technician->can('tasks.view.own'))->toBeTrue()
        ->and($technician->can('journals.create'))->toBeTrue()
        ->and($technician->can('quotes.view'))->toBeFalse()
        ->and($technician->can('invoices.view'))->toBeFalse();
});

it('applique les décisions du 17 novembre 2026', function () {
    expect(userWithRole('project_manager')->can('quotes.validate'))->toBeFalse()
        ->and(userWithRole('sales')->can('costs.view'))->toBeTrue()
        ->and(userWithRole('manager')->can('users.manage'))->toBeTrue()
        ->and(userWithRole('manager')->can('records.force_delete'))->toBeFalse()
        ->and(userWithRole('admin')->can('records.force_delete'))->toBeTrue();
});

it('relance le seeder sans doublon', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // Les 7 profils et le compte « Lecture seule »
    expect(Role::count())->toBe(count(\App\Support\Permissions::ROLE_LABELS));
});
