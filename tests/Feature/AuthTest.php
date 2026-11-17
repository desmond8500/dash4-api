<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('répond sur la route de santé', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('connecte un utilisateur actif et renvoie un jeton et ses permissions', function () {
    $user = userWithRole('sales', ['email' => 'paul@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'paul@example.com',
        'password' => 'password',
        'device_name' => 'Tests',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'email', 'roles', 'permissions']])
        ->assertJsonPath('user.account_type.value', 'manager');

    expect($response->json('user.permissions'))->toContain('quotes.validate', 'prices.view');
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('refuse un mauvais mot de passe', function () {
    userWithRole('sales', ['email' => 'paul@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'paul@example.com', 'password' => 'mauvais'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('refuse la connexion d\'un compte désactivé', function () {
    userWithRole('sales', ['email' => 'paul@example.com', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', ['email' => 'paul@example.com', 'password' => 'password'])
        ->assertForbidden();
});

it('bloque un jeton existant dès que le compte est désactivé', function () {
    $user = userWithRole('sales');
    Sanctum::actingAs($user);
    $user->update(['is_active' => false]);

    $this->getJson('/api/v1/auth/me')->assertForbidden();
});

it('renvoie l\'utilisateur connecté', function () {
    Sanctum::actingAs(userWithRole('accountant'));

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles.0.name', 'accountant')
        ->assertJsonPath('data.roles.0.label', 'Comptable');
});

it('déconnecte en supprimant le jeton utilisé', function () {
    $user = userWithRole('sales');
    $token = $user->createToken('Tests')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('refuse l\'accès sans jeton', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});
