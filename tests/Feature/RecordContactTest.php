<?php

use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;

it('lie des contacts à un projet avec leur rôle, les réordonne, change le rôle et les retire', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $architect = Contact::factory()->create(['firstname' => 'Awa', 'lastname' => 'Ndiaye']); // d'un autre client
    $owner = Contact::factory()->create(['client_id' => $client->id, 'firstname' => 'Moussa', 'lastname' => 'Fall']);
    $owner->phones()->create(['label' => 'Mobile', 'number' => '77 000 00 00']);

    $first = $this->postJson("/api/v1/projects/{$project->id}/contacts", ['contact_id' => $owner->id, 'role' => "Maître d'ouvrage"])
        ->assertCreated()
        ->assertJsonPath('data.contact.full_name', 'Moussa Fall')
        ->assertJsonPath('data.contact.phones.0.number', '77 000 00 00')
        ->json('data');
    $second = $this->postJson("/api/v1/projects/{$project->id}/contacts", ['contact_id' => $architect->id])->assertCreated()->json('data');

    // Déjà lié : pas de doublon, le rôle est mis à jour
    $this->postJson("/api/v1/projects/{$project->id}/contacts", ['contact_id' => $architect->id, 'role' => 'Architecte'])
        ->assertOk()->assertJsonPath('data.id', $second['id'])->assertJsonPath('data.role', 'Architecte');

    $this->getJson("/api/v1/projects/{$project->id}/contacts")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.role', "Maître d'ouvrage");

    $this->postJson("/api/v1/projects/{$project->id}/contacts/order", ['ids' => [$second['id'], $first['id']]])
        ->assertOk()->assertJsonPath('data.0.contact.full_name', 'Awa Ndiaye');

    $this->patchJson("/api/v1/record-contacts/{$first['id']}", ['role' => null])->assertOk()->assertJsonPath('data.role', null);
    $this->deleteJson("/api/v1/record-contacts/{$first['id']}")->assertOk();
    $this->getJson("/api/v1/projects/{$project->id}/contacts")->assertJsonCount(1, 'data');
    expect(Contact::find($owner->id))->not->toBeNull();

    // Un contact supprimé n'apparaît plus
    $architect->delete();
    $this->getJson("/api/v1/projects/{$project->id}/contacts")->assertJsonCount(0, 'data');
});

it('lie des contacts à un devis, même accepté, et les reprend à la duplication', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $contact = Contact::factory()->create(['client_id' => $client->id]);
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Alarme'])->json('data');
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])->assertOk();

    $this->postJson("/api/v1/quotes/{$quote['id']}/contacts", ['contact_id' => $contact->id, 'role' => 'Signataire'])->assertCreated();

    $copy = $this->postJson("/api/v1/quotes/{$quote['id']}/duplicate")->assertCreated()->json('data');
    $this->getJson("/api/v1/quotes/{$copy['id']}/contacts")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'Signataire');
});

it('refuse de lier sans pouvoir modifier la fiche, et refuse un contact inconnu', function () {
    $manager = userWithRole('manager');
    $other = userWithRole('project_manager');
    $project = Project::factory()->create();
    $contact = Contact::factory()->create();

    Sanctum::actingAs($other);
    $this->postJson("/api/v1/projects/{$project->id}/contacts", ['contact_id' => $contact->id])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/projects/{$project->id}/contacts", ['contact_id' => 999999])->assertStatus(422);
    $this->getJson('/api/v1/invoices/1/contacts')->assertNotFound();
});
