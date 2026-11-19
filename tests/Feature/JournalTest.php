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

it('garde les photos d\'une section quand on modifie les sections, et les retire avec la section', function () {
    Storage::fake('local');
    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);
    $journal = $this->postJson('/api/v1/journals', [
        'title' => 'Dépannage', 'date' => '2026-11-19',
        'sections' => [['title' => 'Travaux réalisés', 'body' => 'A'], ['title' => 'Reste à faire', 'body' => 'B']],
    ])->assertCreated()->json('data');
    [$done, $todo] = $journal['sections'];

    $this->post("/api/v1/journal-sections/{$done['id']}/documents", ['file' => UploadedFile::fake()->image('avant.jpg')], ['Accept' => 'application/json'])->assertCreated();

    // Section modifiée (même id) : la photo reste ; nouvelle section ajoutée ; « Reste à faire » retirée
    $updated = $this->patchJson("/api/v1/journals/{$journal['id']}", ['sections' => [
        ['id' => $done['id'], 'title' => 'Travaux réalisés', 'body' => 'A modifié'],
        ['title' => 'Remarques', 'body' => 'C'],
    ]])->assertOk()->json('data');

    expect($updated['sections'])->toHaveCount(2)
        ->and($updated['sections'][0]['id'])->toBe($done['id'])
        ->and($updated['sections'][0]['photos'])->toHaveCount(1)
        ->and(collect($updated['sections'])->pluck('id'))->not->toContain($todo['id']);
    expect(substr($this->get("/api/v1/journals/{$journal['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $this->patchJson("/api/v1/journals/{$journal['id']}", ['sections' => []])->assertOk();
    expect(\App\Models\Document::query()->count())->toBe(0);

    // Un autre technicien ne peut pas ajouter de photo
    $other = Journal::factory()->create(['title' => 'Autre']);
    $section = $other->sections()->create(['position' => 0, 'title' => 'X']);
    $this->post("/api/v1/journal-sections/{$section->id}/documents", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();
});

it('envoie le rapport par e-mail avec son PDF et le garde dans l\'historique', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $client = Client::factory()->create();
    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);
    $journal = $this->postJson('/api/v1/journals', ['title' => 'Maintenance', 'date' => '2026-11-19', 'client_id' => $client->id])->json('data');

    $this->getJson("/api/v1/journals/{$journal['id']}/email-draft")->assertOk()
        ->assertJsonPath('data.subject', "Rapport d'intervention du 19/11/2026 — Maintenance");
    $this->postJson("/api/v1/journals/{$journal['id']}/send", ['to' => ['client@exemple.sn'], 'subject' => 'Rapport', 'message' => 'Bonjour'])
        ->assertOk()->assertJsonPath('data.emails.0.attachments', ['Rapport 2026-11-19.pdf']);

    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\QuoteMail::class, fn ($m) => $m->hasTo('client@exemple.sn') && $m->document instanceof Journal);

    // Sans droit sur le journal : refusé
    Sanctum::actingAs(userWithRole('technician'));
    $this->postJson("/api/v1/journals/{$journal['id']}/send", ['to' => ['a@b.sn'], 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
});
