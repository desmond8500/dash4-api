<?php

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('commente une tâche et prévient les personnes mentionnées qui la voient', function () {
    $pm = userWithRole('project_manager', ['firstname' => 'Awa', 'lastname' => 'Ndiaye']);
    $tech = userWithRole('technician');
    $outsider = userWithRole('technician');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Pose caméras']);
    $task->assignees()->attach($tech->id);

    // Le technicien affecté voit la tâche : il peut commenter et mentionner le chef de projet
    Sanctum::actingAs($tech);
    $mentionable = collect($this->getJson("/api/v1/tasks/{$task->id}/mentionable")->assertOk()->json('data'))->pluck('id');
    expect($mentionable)->toContain($pm->id)->not->toContain($outsider->id)->not->toContain($tech->id);

    $uuid = (string) Str::uuid();
    $comment = $this->postJson("/api/v1/tasks/{$task->id}/comments", [
        'client_uuid' => $uuid, 'body' => '@Awa Ndiaye il manque une caméra', 'mentions' => [$pm->id, $outsider->id],
    ])->assertCreated()->json('data');
    expect($comment['mentions'])->toHaveCount(1)->and($comment['mentions'][0]['id'])->toBe($pm->id)
        ->and($comment['can'])->toMatchArray(['update' => true, 'delete' => true]);
    expect($pm->notifications()->count())->toBe(1)->and($outsider->notifications()->count())->toBe(0)
        ->and($pm->notifications()->first()->data)->toMatchArray(['kind' => 'comment.mention', 'subject_type' => 'tasks', 'subject_id' => $task->id]);

    // Renvoi depuis la file d'attente du téléphone : pas de doublon, pas de nouvelle notification
    $this->postJson("/api/v1/tasks/{$task->id}/comments", ['client_uuid' => $uuid, 'body' => 'x', 'mentions' => [$pm->id]])->assertOk();
    expect(Comment::count())->toBe(1)->and($pm->notifications()->count())->toBe(1);

    // Photo jointe par l'auteur
    Storage::fake('local');
    $this->post("/api/v1/comments/{$comment['id']}/documents", ['file' => UploadedFile::fake()->image('cam.jpg')], ['Accept' => 'application/json'])->assertCreated();

    // Quelqu'un qui ne voit pas la tâche : ni lecture ni commentaire
    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertForbidden();
    $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Bonjour'])->assertForbidden();

    // Le chef de projet lit, ne modifie pas le commentaire d'un autre
    Sanctum::actingAs($pm);
    $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertJsonCount(1, 'data')->assertJsonPath('data.0.files.0.name', 'cam.jpg')
        ->assertJsonPath('data.0.can.update', false);
    $this->patchJson("/api/v1/comments/{$comment['id']}", ['body' => 'Changé'])->assertForbidden();
    $this->post("/api/v1/comments/{$comment['id']}/documents", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();
});

it('laisse l\'auteur modifier et supprimer son commentaire, et ne prévient que les nouvelles mentions', function () {
    $manager = userWithRole('manager');
    $sales = userWithRole('sales');
    $accountant = userWithRole('accountant');
    $project = Project::factory()->create();

    Sanctum::actingAs($manager);
    $comment = $this->postJson("/api/v1/projects/{$project->id}/comments", ['body' => 'Point chantier', 'mentions' => [$sales->id]])->json('data');
    $since = now()->toIso8601String();
    $this->travel(1)->minutes();

    $edited = $this->patchJson("/api/v1/comments/{$comment['id']}", ['body' => 'Point chantier lundi', 'mentions' => [$sales->id, $accountant->id]])->assertOk()->json('data');
    expect($edited['edited_at'])->not->toBeNull()->and($edited['mentions'])->toHaveCount(2);
    expect($sales->notifications()->count())->toBe(1)->and($accountant->notifications()->count())->toBe(1);

    $this->deleteJson("/api/v1/comments/{$comment['id']}")->assertOk();
    $this->getJson("/api/v1/projects/{$project->id}/comments")->assertJsonCount(0, 'data');
    // En synchro, la suppression revient pour que le téléphone retire le commentaire
    $this->getJson("/api/v1/projects/{$project->id}/comments?updated_since=".urlencode($since))->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.deleted_at', fn ($v) => $v !== null);

    $this->getJson("/api/v1/journal-sections/1/comments")->assertNotFound();
    $this->getJson("/api/v1/comments/1/comments")->assertNotFound();
});
