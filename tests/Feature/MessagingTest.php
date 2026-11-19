<?php

use App\Models\Message;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('local'));

it('ouvre une conversation privée unique et compte les non lus', function () {
    $awa = userWithRole('technician');
    $moussa = userWithRole('project_manager');
    Sanctum::actingAs($awa);

    $conv = $this->postJson('/api/v1/conversations', ['type' => 'direct', 'user_ids' => [$moussa->id]])
        ->assertCreated()->assertJsonPath('data.title', $moussa->full_name)->json('data');
    // La même conversation est reprise, même si l'autre personne la crée
    Sanctum::actingAs($moussa);
    $this->postJson('/api/v1/conversations', ['type' => 'direct', 'user_ids' => [$awa->id]])->assertOk()->assertJsonPath('data.id', $conv['id']);

    Sanctum::actingAs($awa);
    $uuid = (string) Str::uuid();
    $this->postJson("/api/v1/conversations/{$conv['id']}/messages", ['body' => 'Bonjour, je suis sur place.', 'client_uuid' => $uuid])->assertCreated();
    $this->postJson("/api/v1/conversations/{$conv['id']}/messages", ['body' => 'Bonjour, je suis sur place.', 'client_uuid' => $uuid])->assertOk();
    $this->postJson("/api/v1/conversations/{$conv['id']}/messages", ['body' => '  '])->assertStatus(422);
    $this->getJson('/api/v1/conversations/unread')->assertJsonPath('data.count', 0);

    Sanctum::actingAs($moussa);
    $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 1)
        ->assertJsonPath('data.0.last_message.excerpt', 'Bonjour, je suis sur place.')->assertJsonPath('meta.unread_total', 1);
    $this->postJson("/api/v1/conversations/{$conv['id']}/read")->assertJsonPath('data.count', 0);
    $first = $this->getJson("/api/v1/conversations/{$conv['id']}/messages")->assertJsonCount(1, 'data')->json('data.0.id');
    $this->postJson("/api/v1/conversations/{$conv['id']}/messages", ['body' => 'Bien reçu.'])->assertCreated();
    $this->getJson("/api/v1/conversations/{$conv['id']}/messages?after_id={$first}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Bien reçu.');
});

it('crée un groupe lié à un projet, hors ligne, et en gère les membres', function () {
    $pm = userWithRole('project_manager');
    $tech = userWithRole('technician');
    $other = userWithRole('sales');
    $outsider = userWithRole('accountant');
    $project = Project::factory()->create(['manager_id' => $pm->id, 'name' => 'Tour Diamniadio']);
    $foreign = Project::factory()->create();
    Sanctum::actingAs($pm);

    // Un projet qu'on ne voit pas ne se lie pas
    $this->postJson('/api/v1/conversations', ['type' => 'group', 'user_ids' => [$tech->id], 'project_id' => $foreign->id])->assertForbidden();

    $uuid = (string) Str::uuid();
    $conv = $this->postJson('/api/v1/conversations', ['type' => 'group', 'user_ids' => [$tech->id], 'project_id' => $project->id, 'client_uuid' => $uuid])
        ->assertCreated()->assertJsonPath('data.title', 'Tour Diamniadio')->assertJsonCount(2, 'data.members')->json('data');
    // Message envoyé hors ligne vers la conversation encore inconnue : par son identifiant hors ligne
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'Réunion demain 8 h.'])->assertCreated();
    $this->getJson("/api/v1/conversations?project_id={$project->id}")->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/conversations/{$conv['id']}/members", ['user_ids' => [$other->id]])->assertOk()->assertJsonCount(3, 'data.members');

    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/conversations/{$conv['id']}")->assertForbidden();
    $this->getJson("/api/v1/conversations/{$conv['id']}/messages")->assertForbidden();
    $this->getJson('/api/v1/conversations')->assertJsonCount(0, 'data');

    Sanctum::actingAs($tech);
    $this->patchJson("/api/v1/conversations/{$conv['id']}", ['title' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/conversations/{$conv['id']}/members/{$other->id}")->assertForbidden();
    $this->deleteJson("/api/v1/conversations/{$conv['id']}/members/{$tech->id}")->assertOk();
    $this->getJson("/api/v1/conversations/{$conv['id']}")->assertForbidden();
});

it('joint des fichiers, modifie et supprime ses messages', function () {
    $a = userWithRole('manager');
    $b = userWithRole('technician');
    $c = userWithRole('sales');
    Sanctum::actingAs($a);
    $conv = $this->postJson('/api/v1/conversations', ['type' => 'direct', 'user_ids' => [$b->id]])->json('data.id');
    $msg = $this->postJson("/api/v1/conversations/{$conv}/messages", ['with_files' => true])->assertCreated()->json('data.id');
    $doc = $this->post("/api/v1/messages/{$msg}/documents", ['file' => UploadedFile::fake()->image('photo.jpg')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    $this->getJson("/api/v1/conversations/{$conv}/messages")->assertJsonCount(1, 'data.0.files');

    Sanctum::actingAs($b);
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertOk();
    $this->patchJson("/api/v1/messages/{$msg}", ['body' => 'Pirate'])->assertForbidden();
    $this->post("/api/v1/messages/{$msg}/documents", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();

    Sanctum::actingAs($c);
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertForbidden();

    Sanctum::actingAs($a);
    $this->patchJson("/api/v1/messages/{$msg}", ['body' => 'Photo du tableau'])->assertOk()->assertJsonPath('data.edited_at', fn ($v) => $v !== null);
    $this->deleteJson("/api/v1/messages/{$msg}")->assertOk();
    $this->getJson("/api/v1/conversations/{$conv}/messages")->assertJsonPath('data.0.body', null)->assertJsonPath('data.0.files', []);
    expect(Message::withTrashed()->find($msg)->deleted_at)->not->toBeNull();
});
