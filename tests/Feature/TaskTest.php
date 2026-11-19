<?php

use App\Models\ClientRequest;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('crée une tâche planifiée, l\'affecte et prévient la personne', function () {
    Notification::fake();
    $tech = userWithRole('technician');
    Sanctum::actingAs(userWithRole('project_manager'));
    $project = Project::factory()->create(['address' => 'Almadies, Dakar']);

    $task = $this->postJson('/api/v1/tasks', [
        'title' => 'Poser 4 caméras', 'project_id' => $project->id, 'priority' => 'high',
        'starts_at' => '2026-11-23 08:30', 'ends_at' => '2026-11-23 17:00', 'due_date' => '2026-11-24',
        'assignee_ids' => [$tech->id], 'subtasks' => ['Tirer les câbles', 'Fixer les caméras', 'Régler l\'enregistreur'],
        'client_uuid' => (string) Str::uuid(),
    ])->assertCreated()->json('data');

    expect($task)->toMatchArray(['starts_at' => '2026-11-23T08:30:00', 'due_date' => '2026-11-24', 'subtasks_count' => 3])
        ->and($task['status']['label'])->toBe('À faire')
        ->and($task['priority']['label'])->toBe('Urgente')
        ->and($task['project']['address'])->toBe('Almadies, Dakar')
        ->and($task['assignees'][0]['id'])->toBe($tech->id);
    Notification::assertSentTo($tech, TaskAssignedNotification::class);

    $this->postJson('/api/v1/tasks', ['title' => 'X', 'starts_at' => '2026-11-23 10:00', 'ends_at' => '2026-11-23 09:00'])
        ->assertJsonPath('errors.ends_at.0', 'La fin doit être après le début.');
});

it('laisse le technicien faire avancer ses tâches, sans les modifier ni voir les autres', function () {
    $tech = userWithRole('technician');
    $task = Task::factory()->create(['title' => 'Maintenance alarme']);
    $task->assignees()->attach($tech);
    $task->subtasks()->create(['title' => 'Tester les détecteurs']);
    Task::factory()->create(['title' => 'Pas la mienne']);

    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/tasks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.update', false)->assertJsonPath('data.0.can.progress', true);

    $sub = $task->subtasks()->first();
    $this->patchJson("/api/v1/subtasks/{$sub->id}", ['is_done' => true])
        ->assertJsonPath('data.status.value', 'in_progress')->assertJsonPath('data.subtasks_done', 1);
    $this->patchJson("/api/v1/tasks/{$task->id}", ['status' => 'done'])->assertOk()->assertJsonPath('data.completer.id', $tech->id);
    $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Autre'])->assertForbidden();
    $this->patchJson("/api/v1/tasks/{$task->id}", ['assignee_ids' => []])->assertForbidden();
    $this->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => 'Détecteur du couloir remplacé'])->assertCreated();
    $this->postJson('/api/v1/tasks', ['title' => 'X'])->assertForbidden();

    // Rouvrir efface la fin
    $this->patchJson("/api/v1/tasks/{$task->id}", ['status' => 'in_progress'])->assertJsonPath('data.completed_at', null);
});

it('montre au chef de projet les tâches de ses projets', function () {
    $pm = userWithRole('project_manager');
    $mine = Project::factory()->create(['manager_id' => $pm->id]);
    Task::factory()->create(['project_id' => $mine->id, 'title' => 'Du projet']);
    Task::factory()->create(['title' => 'Ailleurs']);

    Sanctum::actingAs($pm);
    $this->getJson('/api/v1/tasks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.update', true);
});

it('donne le planning de la semaine et les tâches en retard', function () {
    $tech = userWithRole('technician');
    Sanctum::actingAs(userWithRole('manager'));
    $make = fn (array $a) => tap(Task::factory()->create($a), fn ($t) => $t->assignees()->attach($tech));
    $make(['title' => 'Lundi', 'starts_at' => '2026-11-23 08:00', 'ends_at' => '2026-11-23 12:00']);
    $make(['title' => 'Sur deux jours', 'starts_at' => '2026-11-20 08:00', 'ends_at' => '2026-11-24 18:00']);
    $make(['title' => 'Semaine suivante', 'starts_at' => '2026-12-01 08:00']);
    $make(['title' => 'Sans date', 'due_date' => now()->subDay()->toDateString()]);

    $week = $this->getJson('/api/v1/tasks?from=2026-11-23&to=2026-11-29')->json('data');
    expect(collect($week)->pluck('title')->sort()->values()->all())->toBe(['Lundi', 'Sur deux jours']);
    $this->getJson('/api/v1/tasks?unscheduled=1&open=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_overdue', true);
    $this->getJson("/api/v1/tasks?assigned_to={$tech->id}&overdue=1")->assertJsonCount(1, 'data');

    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/tasks/summary')->assertJson(['data' => ['mine_open' => 4, 'mine_overdue' => 1]]);
});

it('transforme une demande client en tâche affectée à la personne en charge', function () {
    $sales = userWithRole('manager');
    $tech = userWithRole('technician');
    Sanctum::actingAs($sales);
    $request = ClientRequest::forceCreate(['body' => 'Le portail ne s\'ouvre plus', 'contact_name' => 'M. Diop', 'assigned_to' => $tech->id, 'created_by' => $sales->id]);

    $result = $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'task'])->assertCreated()->json('data');

    expect($result['task']['title'])->toBe('Le portail ne s\'ouvre plus')
        ->and($result['task']['client_request_id'])->toBe($request->id)
        ->and($result['task']['assignees'][0]['id'])->toBe($tech->id)
        ->and($result['request']['converted_to']['type'])->toBe('task');
});
