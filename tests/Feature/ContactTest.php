<?php

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('crée un contact rattaché à un client', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create(['name' => 'Alpha']);

    $this->postJson('/api/v1/contacts', [
        'client_id' => $client->id,
        'civility' => 'mrs',
        'firstname' => 'Awa',
        'lastname' => 'Diop',
        'job_title' => 'Directrice technique',
        'email' => 'awa@alpha.sn',
        'phones' => [['label' => 'Mobile', 'number' => '+221 77 000 00 00']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Awa Diop')
        ->assertJsonPath('data.civility.label', 'Mme')
        ->assertJsonPath('data.client.name', 'Alpha')
        ->assertJsonPath('data.phones.0.label', 'Mobile');
});

it('crée un contact sans client, avec sa société', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $this->postJson('/api/v1/contacts', ['firstname' => 'Moussa', 'company' => 'Cabinet BTP'])
        ->assertCreated()
        ->assertJsonPath('data.client_id', null)
        ->assertJsonPath('data.company', 'Cabinet BTP');
});

it('refuse un client supprimé ou inexistant', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $client->delete();

    $this->postJson('/api/v1/contacts', ['firstname' => 'Awa', 'client_id' => $client->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');
});

it('filtre les contacts par client et par recherche', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $alpha = Client::factory()->create();
    Contact::factory()->for($alpha)->create(['firstname' => 'Awa']);
    Contact::factory()->create(['firstname' => 'Moussa']);

    $this->getJson("/api/v1/contacts?client_id={$alpha->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/contacts?search=mous')->assertJsonCount(1, 'data')->assertJsonPath('data.0.firstname', 'Moussa');
});

it('ne crée pas de doublon de contact (client_uuid)', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/contacts', ['client_uuid' => $uuid, 'firstname' => 'Awa'])->assertCreated();
    $this->postJson('/api/v1/contacts', ['client_uuid' => $uuid, 'firstname' => 'Awa'])->assertOk();

    expect(Contact::count())->toBe(1);
});

it('garde le contact quand son client est supprimé définitivement', function () {
    $client = Client::factory()->create();
    $contact = Contact::factory()->for($client)->create();

    $client->forceDelete();

    expect($contact->fresh()->client_id)->toBeNull();
});

it('applique les droits du module clients aux contacts', function () {
    $contact = Contact::factory()->create();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/contacts/{$contact->id}")->assertForbidden();

    Sanctum::actingAs(userWithRole('sales'));
    $this->deleteJson("/api/v1/contacts/{$contact->id}")->assertForbidden();
});

it('trouve un contact par prénom et nom ou par une adresse e-mail secondaire', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $awa = Contact::factory()->create(['firstname' => 'Awa', 'lastname' => 'Diop', 'email' => null]);
    $awa->emails()->create(['address' => 'compta@sonatel.sn']);
    Contact::factory()->create(['firstname' => 'Awa', 'lastname' => 'Ndiaye']);

    $this->getJson('/api/v1/contacts?search=awa diop')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $awa->id);
    $this->getJson('/api/v1/contacts?search=compta@sonatel')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $awa->id);
});
