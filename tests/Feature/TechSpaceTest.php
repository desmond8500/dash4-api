<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TechAccessRequest;
use App\Models\TechShare;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('envoie une information temporaire aux destinataires, qui la voient jusqu\'à son expiration', function () {
    Storage::fake('local');
    $pm = userWithRole('project_manager');
    $tech = userWithRole('technician');
    $other = userWithRole('technician');
    $project = Project::factory()->create(['manager_id' => $pm->id]);

    Sanctum::actingAs($pm);
    $share = $this->postJson('/api/v1/tech-shares', [
        'category' => 'access', 'title' => 'Code portail villa', 'content' => 'Code : 4589#',
        'project_id' => $project->id, 'expires_at' => now()->addHours(4)->toIso8601String(), 'recipients' => [$tech->id],
    ])->assertCreated()->json('data');
    $this->post("/api/v1/tech-shares/{$share['id']}/documents", ['file' => UploadedFile::fake()->image('plan.jpg')], ['Accept' => 'application/json'])->assertCreated();
    expect($tech->notifications()->count())->toBe(1);

    // Pour un projet qui n'est pas le sien : refusé
    $this->postJson('/api/v1/tech-shares', [
        'title' => 'X', 'project_id' => Project::factory()->create()->id, 'expires_at' => now()->addDay()->toIso8601String(), 'recipients' => [$tech->id],
    ])->assertForbidden();
    // Sans expiration : refusé
    $this->postJson('/api/v1/tech-shares', ['title' => 'X', 'recipients' => [$tech->id]])->assertJsonValidationErrors('expires_at');

    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/tech-shares')->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', 'Code : 4589#')
        ->assertJsonPath('data.0.read_at', null);
    $this->postJson("/api/v1/tech-shares/{$share['id']}/read")->assertOk()->assertJsonPath('data.is_recipient', true);
    $this->patchJson("/api/v1/tech-shares/{$share['id']}", ['title' => 'Y'])->assertForbidden();

    Sanctum::actingAs($other);
    $this->getJson('/api/v1/tech-shares')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/tech-shares/{$share['id']}")->assertForbidden();

    // Heure passée : plus de contenu, même avant le passage de la commande ; puis la commande efface tout
    $this->travel(5)->hours();
    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/tech-shares')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/tech-shares/{$share['id']}")->assertJsonPath('data.content', null)->assertJsonPath('data.expired', true);
    $this->getJson('/api/v1/tech-shares?updated_since='.urlencode(now()->subDay()->toIso8601String()))
        ->assertJsonPath('data.0.expired', true)->assertJsonPath('data.0.content', null);

    $this->artisan('tech-shares:expire')->assertSuccessful();
    $model = TechShare::find($share['id']);
    expect($model->content)->toBeNull()->and($model->expired_at)->not->toBeNull()->and($model->documents()->withTrashed()->count())->toBe(0);
});

it('fait expirer une information liée à la fin de la tâche', function () {
    $manager = userWithRole('manager');
    $tech = userWithRole('technician');
    $task = Task::factory()->create();
    Sanctum::actingAs($manager);
    $share = $this->postJson('/api/v1/tech-shares', [
        'title' => 'Clé du local', 'content' => 'Boîte à clés : 1290', 'task_id' => $task->id, 'expires_with_task' => true, 'recipients' => [$tech->id],
    ])->assertCreated()->json('data');
    expect($share['expires_at'])->toBeNull();
    $this->postJson('/api/v1/tech-shares', ['title' => 'X', 'expires_with_task' => true, 'recipients' => [$tech->id]])->assertStatus(422);

    $task->forceFill(['status' => 'done'])->save();
    $this->artisan('tech-shares:expire')->assertSuccessful();
    expect(TechShare::find($share['id'])->content)->toBeNull();
});

it('laisse le technicien demander un accès, et le chef de projet décider pour ses projets', function () {
    $pm = userWithRole('project_manager');
    $otherPm = userWithRole('project_manager');
    $tech = userWithRole('technician');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $task->assignees()->attach($tech->id);

    Sanctum::actingAs($tech);
    $uuid = (string) Str::uuid();
    $request = $this->postJson('/api/v1/tech-access-requests', [
        'client_uuid' => $uuid, 'subject' => 'Code de la baie de brassage', 'reason' => 'Raccordement des caméras', 'task_id' => $task->id,
    ])->assertCreated()->json('data');
    expect($request['project_id'])->toBe($project->id)->and($pm->notifications()->count())->toBe(1);
    // Renvoi depuis la file d'attente du téléphone : pas de doublon
    $this->postJson('/api/v1/tech-access-requests', ['client_uuid' => $uuid, 'subject' => 'Code de la baie de brassage'])->assertOk();
    expect(TechAccessRequest::count())->toBe(1);
    $this->postJson("/api/v1/tech-access-requests/{$request['id']}/decide", ['decision' => 'approved'])->assertForbidden();

    Sanctum::actingAs($otherPm);
    $this->getJson('/api/v1/tech-access-requests')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/tech-access-requests/{$request['id']}/decide", ['decision' => 'approved'])->assertForbidden();

    Sanctum::actingAs($pm);
    $this->getJson('/api/v1/tech-access-requests?pending=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.decide', true);
    $this->postJson("/api/v1/tech-access-requests/{$request['id']}/decide", ['decision' => 'rejected'])->assertJsonValidationErrors('response');
    $decided = $this->postJson("/api/v1/tech-access-requests/{$request['id']}/decide", [
        'decision' => 'approved', 'response' => 'Voici le code',
        'share' => ['content' => 'Baie : 7781', 'expires_at' => now()->addDay()->toIso8601String()],
    ])->assertOk()->json('data');
    expect($decided['status']['value'])->toBe('approved')->and($decided['tech_share_id'])->not->toBeNull();
    $this->postJson("/api/v1/tech-access-requests/{$request['id']}/decide", ['decision' => 'rejected', 'response' => 'Non'])->assertStatus(422);

    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/tech-shares')->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', 'Baie : 7781')
        ->assertJsonPath('data.0.title', 'Code de la baie de brassage');
    $this->getJson('/api/v1/tech-access-requests')->assertJsonPath('data.0.status.label', 'Acceptée')->assertJsonPath('data.0.can.delete', false);
    expect($tech->notifications()->count())->toBe(2);
});

it('prévient les managers d\'une demande sans projet et laisse le technicien l\'annuler', function () {
    $manager = userWithRole('manager');
    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);
    $request = $this->postJson('/api/v1/tech-access-requests', ['subject' => 'Badge du dépôt'])->assertCreated()->json('data');
    expect($manager->notifications()->count())->toBe(1);
    $this->deleteJson("/api/v1/tech-access-requests/{$request['id']}")->assertOk();
});
