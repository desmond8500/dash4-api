<?php

use App\Models\Client;
use App\Support\Permissions;
use Laravel\Sanctum\Sanctum;

it('laisse le compte lecture seule tout consulter, sans coûts, et refuse toute modification', function () {
    $viewer = userWithRole(Permissions::ROLE_VIEWER);
    $client = Client::factory()->create();
    Sanctum::actingAs($viewer);

    expect($viewer->can('quotes.view'))->toBeTrue()
        ->and($viewer->can('prices.view'))->toBeTrue()
        ->and($viewer->can('costs.view'))->toBeFalse()
        ->and($viewer->can('bank.view'))->toBeFalse()
        ->and($viewer->can('clients.create'))->toBeFalse();

    $this->getJson('/api/v1/clients')->assertOk();
    $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
    $this->patchJson("/api/v1/clients/{$client->id}", ['name' => 'X'])->assertForbidden();
    // Même ce que tout compte peut faire d'habitude
    $this->patchJson('/api/v1/me', ['firstname' => 'Y'])->assertForbidden()->assertJsonPath('message', 'Compte en lecture seule : aucune modification possible.');
});

it('laisse le compte lecture seule se déconnecter', function () {
    $viewer = userWithRole(Permissions::ROLE_VIEWER);
    $token = $viewer->createToken('Démo')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    expect($viewer->tokens()->count())->toBe(0);
});
