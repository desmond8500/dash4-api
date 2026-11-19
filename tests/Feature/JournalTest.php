<?php

use App\Models\Client;
use App\Models\Contact;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('écrit un journal depuis une tâche : projet, client et intervenants repris', function () {
    $tech = userWithRole('technician');
    $mate = userWithRole('technician');
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id, 'client_id' => $client->id]);
    $task->assignees()->attach([$tech->id, $mate->id]);
    $contact = Contact::factory()->create(['client_id' => $client->id, 'firstname' => 'Awa', 'lastname' => 'Fall']);

    Sanctum::actingAs($tech);
    $journal = $this->postJson('/api/v1/journals', [
        'client_uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'title' => 'Pose des caméras R+1', 'type' => 'installation',
        'date' => '2026-11-19', 'starts_at' => '2026-11-19 08:00', 'ends_at' => '2026-11-19 16:30',
        'latitude' => 14.7167, 'longitude' => -17.4677,
        'sections' => [['title' => 'Travaux réalisés', 'body' => '4 caméras posées'], ['title' => 'Reste à faire', 'body' => 'Réglage enregistreur']],
    ])->assertCreated()->json('data');

    expect($journal)->toMatchArray(['project_id' => $project->id, 'client_id' => $client->id, 'starts_at' => '2026-11-19T08:00:00', 'latitude' => 14.7167])
        ->and($journal['type']['label'])->toBe('Installation')
        ->and($journal['sections'])->toHaveCount(2)
        ->and(collect($journal['participants'])->pluck('user_id')->sort()->values()->all())->toBe(collect([$tech->id, $mate->id])->sort()->values()->all());

    // Intervenants remplacés : un contact du client et un nom libre
    $updated = $this->patchJson("/api/v1/journals/{$journal['id']}", ['participants' => [
        ['user_id' => $tech->id], ['contact_id' => $contact->id, 'role' => 'Gardien'], ['name' => 'Électricien du client'],
    ]])->assertOk()->json('data');
    expect(collect($updated['participants'])->pluck('name')->all())->toContain('Awa Fall', 'Électricien du client');

    // Photos et PDF
    Storage::fake('local');
    $this->post("/api/v1/journals/{$journal['id']}/documents", ['file' => UploadedFile::fake()->image('camera.jpg')], ['Accept' => 'application/json'])->assertCreated();
    expect(substr($this->get("/api/v1/journals/{$journal['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('ne montre au technicien que ses journaux, et le chef de projet voit ceux de ses projets', function () {
    $tech = userWithRole('technician');
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    Journal::factory()->create(['created_by' => $tech->id, 'title' => 'Le mien']);
    $other = Journal::factory()->create(['title' => 'Projet du chef', 'project_id' => $project->id]);
    Journal::factory()->create(['title' => 'Ailleurs']);

    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/journals')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Le mien');
    $this->patchJson("/api/v1/journals/{$other->id}", ['title' => 'X'])->assertForbidden();

    Sanctum::actingAs($pm);
    $this->getJson('/api/v1/journals')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.update', true);

    Sanctum::actingAs(userWithRole('accountant'));
    $this->getJson('/api/v1/journals')->assertForbidden();
});

it('filtre les journaux par projet et par période', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $project = Project::factory()->create();
    Journal::factory()->create(['project_id' => $project->id, 'date' => '2026-11-10']);
    Journal::factory()->create(['project_id' => $project->id, 'date' => '2026-11-20']);
    Journal::factory()->create(['date' => '2026-11-20']);

    $this->getJson("/api/v1/journals?project_id={$project->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/journals?from=2026-11-16&to=2026-11-22')->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/journals', ['title' => 'X', 'date' => '2026-11-19', 'latitude' => 14])->assertJsonValidationErrors('longitude');
});
