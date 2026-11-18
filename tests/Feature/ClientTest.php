<?php

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('liste les clients avec recherche et filtres', function () {
    Sanctum::actingAs(userWithRole('sales'));
    Client::factory()->create(['name' => 'Société Alpha', 'status' => 'prospect']);
    Client::factory()->create(['name' => 'Beta Construction']);

    $this->getJson('/api/v1/clients?search=alpha')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Société Alpha')
        ->assertJsonPath('data.0.status.label', 'Prospect')
        ->assertJsonStructure(['meta' => ['server_time', 'total']]);

    $this->getJson('/api/v1/clients?status=active')->assertJsonCount(1, 'data');
});

it('crée un client avec ses téléphones et e-mails', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $response = $this->postJson('/api/v1/clients', [
        'name' => 'Résidence Les Palmiers',
        'type' => 'company',
        'phones' => [['label' => 'Standard', 'number' => '+221 33 800 00 00']],
        'emails' => [['label' => 'Comptabilité', 'address' => 'compta@palmiers.sn']],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Résidence Les Palmiers')
        ->assertJsonPath('data.phones.0.number', '+221 33 800 00 00')
        ->assertJsonPath('data.emails.0.address', 'compta@palmiers.sn');

    expect(Client::first()->created_by)->not->toBeNull();
});

it('ne crée pas de doublon quand le mobile renvoie la même action (client_uuid)', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/clients', ['client_uuid' => $uuid, 'name' => 'Client A'])->assertCreated();
    $this->postJson('/api/v1/clients', ['client_uuid' => $uuid, 'name' => 'Client A'])->assertOk();

    expect(Client::count())->toBe(1);
});

it('remplace les téléphones à la modification, sans toucher aux e-mails absents', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $client->syncPhonesAndEmails([['number' => '111']], [['address' => 'a@b.sn']]);

    $this->patchJson("/api/v1/clients/{$client->id}", ['phones' => [['number' => '222']]])
        ->assertOk()
        ->assertJsonCount(1, 'data.phones')
        ->assertJsonPath('data.phones.0.number', '222')
        ->assertJsonPath('data.emails.0.address', 'a@b.sn');
});

it('valide les champs avec des messages en français', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $this->postJson('/api/v1/clients', ['type' => 'inconnu'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type'])
        ->assertJsonPath('errors.name.0', 'Le champ nom est obligatoire.');
});

it('réserve la suppression aux administrateurs et managers', function () {
    $client = Client::factory()->create();

    Sanctum::actingAs(userWithRole('sales'));
    $this->deleteJson("/api/v1/clients/{$client->id}")->assertForbidden();

    Sanctum::actingAs(userWithRole('manager'));
    $this->deleteJson("/api/v1/clients/{$client->id}")->assertOk();

    expect(Client::withTrashed()->find($client->id)->trashed())->toBeTrue();
});

it('laisse le comptable consulter sans modifier', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs(userWithRole('accountant'));

    $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
    $this->patchJson("/api/v1/clients/{$client->id}", ['name' => 'X'])->assertForbidden();
    $this->postJson('/api/v1/clients', ['name' => 'X'])->assertForbidden();
});

it('ne montre aucun client à un technicien tant que les tâches ne sont pas en place', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs(userWithRole('technician'));

    $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/clients/{$client->id}")->assertForbidden();
});

it('renvoie la fiche client avec ses contacts', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['firstname' => 'Awa']);

    $this->getJson("/api/v1/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('data.contacts_count', 1)
        ->assertJsonPath('data.contacts.0.firstname', 'Awa');
});

it('synchronise seulement les changements depuis la dernière synchro, suppressions comprises', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $old = Client::factory()->create(['updated_at' => now()->subDays(2)]);
    $deleted = Client::factory()->create(['updated_at' => now()->subDays(2)]);
    $since = now()->subDay()->toIso8601String();

    $deleted->delete();
    $new = Client::factory()->create();

    $response = $this->getJson('/api/v1/clients?updated_since='.urlencode($since))->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($new->id, $deleted->id)->not->toContain($old->id);
    expect(collect($response->json('data'))->firstWhere('id', $deleted->id)['deleted_at'])->not->toBeNull();
});
