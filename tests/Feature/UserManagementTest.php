<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

function newUserPayload(array $overrides = []): array
{
    return array_merge([
        'firstname' => 'Awa',
        'lastname' => 'Diop',
        'email' => 'awa@example.com',
        'account_type' => 'manager',
        'password' => 'MotDePasse2026',
        'roles' => ['sales'],
    ], $overrides);
}

it('permet à un administrateur de créer un utilisateur', function () {
    Sanctum::actingAs(userWithRole('admin'));

    $this->postJson('/api/v1/users', newUserPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'awa@example.com')
        ->assertJsonPath('data.roles.0.name', 'sales');
});

it('permet au manager de gérer les utilisateurs, sauf les administrateurs', function () {
    Sanctum::actingAs(userWithRole('manager'));

    $this->postJson('/api/v1/users', newUserPayload())->assertCreated();

    $this->postJson('/api/v1/users', newUserPayload(['email' => 'chef@example.com', 'roles' => ['admin']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles');

    $admin = userWithRole('admin');
    $this->patchJson("/api/v1/users/{$admin->id}", ['firstname' => 'Pirate'])->assertForbidden();
});

it('refuse la gestion des utilisateurs aux autres profils', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $this->getJson('/api/v1/users')->assertForbidden();
    $this->postJson('/api/v1/users', newUserPayload())->assertForbidden();
});

it('réserve le rôle Technicien aux comptes technicien', function () {
    Sanctum::actingAs(userWithRole('admin'));

    $this->postJson('/api/v1/users', newUserPayload(['account_type' => 'technician', 'roles' => ['sales']]))
        ->assertUnprocessable();

    $this->postJson('/api/v1/users', newUserPayload(['account_type' => 'manager', 'roles' => ['technician']]))
        ->assertUnprocessable();

    $this->postJson('/api/v1/users', newUserPayload(['account_type' => 'technician', 'roles' => ['technician']]))
        ->assertCreated();
});

it('empêche de modifier ses propres rôles', function () {
    $manager = userWithRole('manager');
    Sanctum::actingAs($manager);

    $this->patchJson("/api/v1/users/{$manager->id}", ['roles' => ['manager', 'accountant']])
        ->assertUnprocessable();
});

it('ne permet pas à un administrateur de se désactiver ni de retirer son propre rôle', function () {
    $admin = userWithRole('admin');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}", ['is_active' => false])->assertUnprocessable();
    $this->patchJson("/api/v1/users/{$admin->id}", ['roles' => ['manager']])->assertUnprocessable();

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($admin->fresh()->isAdmin())->toBeTrue();
});

it('permet de retirer un administrateur tant qu\'il en reste un autre', function () {
    $admin = userWithRole('admin');
    $otherAdmin = userWithRole('admin');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$otherAdmin->id}", ['roles' => ['manager']])->assertOk();
    $this->patchJson("/api/v1/users/{$otherAdmin->id}", ['is_active' => false])->assertOk();

    expect(User::role('admin')->where('is_active', true)->count())->toBe(1);
});

it('déconnecte un utilisateur désactivé', function () {
    Sanctum::actingAs(userWithRole('admin'));
    $user = userWithRole('sales');
    $user->createToken('Téléphone');

    $this->patchJson("/api/v1/users/{$user->id}", ['is_active' => false])->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('inscrit les changements de rôles au journal d\'audit', function () {
    Sanctum::actingAs(userWithRole('admin'));
    $user = userWithRole('sales');

    $this->patchJson("/api/v1/users/{$user->id}", ['roles' => ['accountant']])->assertOk();

    $this->assertDatabaseHas('activity_log', [
        'subject_id' => $user->id,
        'event' => 'roles_updated',
    ]);
});
