<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('envoie un plan et y place les équipements', function () {
    $pm = userWithRole('project_manager');
    $tech = userWithRole('technician');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    Task::factory()->create(['project_id' => $project->id])->assignees()->attach($tech->id);

    Sanctum::actingAs($pm);
    $systemId = $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Vidéo'])->json('data.systems.0.id');
    $cameraId = $this->postJson("/api/v1/installed-systems/{$systemId}/equipment", ['name' => 'Caméra entrée'])->json('data.systems.0.equipment.0.id');

    $plan = $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->image('rdc.png', 800, 600), 'name' => 'Plan RDC'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'image')->json('data');
    $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->create('plan.dwg', 100)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->postJson("/api/v1/plans/{$plan['id']}/markers", ['x' => 0.25, 'y' => 0.5, 'equipment_id' => $cameraId, 'color' => '#e11d48'])
        ->assertCreated()->assertJsonPath('data.markers.0.equipment_id', $cameraId);
    $this->postJson("/api/v1/plans/{$plan['id']}/markers", ['x' => 1.5, 'y' => 0.5])->assertJsonValidationErrors('x');

    // Le technicien lit le plan et télécharge le fichier, sans rien modifier
    Sanctum::actingAs($tech);
    $this->getJson("/api/v1/projects/{$project->id}/plans")->assertJsonCount(1, 'data')->assertJsonPath('data.0.markers.0.x', 0.25);
    $this->get("/api/v1/plans/{$plan['id']}/file")->assertOk();
    $this->postJson("/api/v1/plans/{$plan['id']}/markers", ['x' => 0.1, 'y' => 0.1])->assertForbidden();
});

it('transforme un quantitatif en devis, une ligne par article', function () {
    $sales = userWithRole('manager');
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $article = Article::factory()->create(['designation' => 'Détecteur de fumée', 'unit_cost' => 10000, 'coefficient' => 1.5]);

    Sanctum::actingAs($sales);
    $site = $this->postJson("/api/v1/projects/{$project->id}/buildings", ['name' => 'A'])->json('data');
    $level = $this->postJson("/api/v1/buildings/{$site['buildings'][0]['id']}/levels", ['name' => 'RDC'])->json('data.buildings.0.levels.0.id');
    $room = $this->postJson("/api/v1/levels/{$level}/rooms", ['name' => 'Hall'])->json('data.buildings.0.levels.0.rooms.0.id');

    $quantity = $this->postJson("/api/v1/projects/{$project->id}/quantities", ['name' => 'Détection incendie', 'rows' => [
        ['article_id' => $article->id, 'quantity' => 4, 'room_id' => $room],
        ['article_id' => $article->id, 'quantity' => 2],
        ['designation' => 'Câble 2 paires', 'unit' => 'm', 'quantity' => 120],
    ]])->assertCreated()->json('data');
    expect($quantity['rows'][0]['level_id'])->toBe($level)->and($quantity['rows'][0]['article'])->not->toHaveKey('unit_cost');

    $quote = $this->postJson("/api/v1/quantities/{$quantity['id']}/quote")->assertCreated()->json('data');
    $lines = collect($quote['sections'][0]['lines']);
    expect($lines)->toHaveCount(2)
        ->and((float) $lines->firstWhere('designation', 'Détecteur de fumée')['quantity'])->toBe(6.0)
        ->and($quote['project_id'])->toBe($project->id);
    $this->getJson("/api/v1/quantities/{$quantity['id']}")->assertJsonPath('data.quote.id', $quote['id']);
});

it('rédige une fiche de zones et édite le DOE du chantier', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    Sanctum::actingAs($pm);
    $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Alarme intrusion']);

    $sheet = $this->postJson("/api/v1/projects/{$project->id}/sheets", ['title' => 'Zones alarme', 'type' => 'Alarme', 'zones' => [
        ['number' => '1', 'name' => 'Porte d\'entrée', 'equipment_label' => 'Contact magnétique'],
        ['number' => '2', 'name' => 'Hall', 'equipment_label' => 'Détecteur IRP'],
    ]])->assertCreated()->json('data');
    expect($sheet['zones'])->toHaveCount(2);
    $this->patchJson("/api/v1/sheets/{$sheet['id']}", ['zones' => [['number' => '1', 'name' => 'Porte']]])->assertJsonCount(1, 'data.zones');
    expect(substr($this->get("/api/v1/sheets/{$sheet['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->image('r1.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated();
    expect(substr($this->get("/api/v1/projects/{$project->id}/doe")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    Sanctum::actingAs(userWithRole('accountant'));
    $this->get("/api/v1/projects/{$project->id}/doe")->assertForbidden();
});

it('liste tous les plans visibles, avec leur projet, pour le mobile', function () {
    $pm = userWithRole('project_manager');
    $tech = userWithRole('technician');
    $mine = Project::factory()->create(['manager_id' => $pm->id, 'name' => 'Tour Alpha']);
    $other = Project::factory()->create(['name' => 'Villa Beta']);
    Task::factory()->create(['project_id' => $other->id])->assignees()->attach($tech->id);

    Sanctum::actingAs(userWithRole('admin'));
    foreach ([[$mine, 'RDC'], [$mine, 'Étage 1'], [$other, 'Sous-sol']] as [$project, $name]) {
        $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->image('p.png', 400, 300), 'name' => $name], ['Accept' => 'application/json'])->assertCreated();
    }

    Sanctum::actingAs($pm);
    $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.project.name', 'Tour Alpha');
    $this->getJson('/api/v1/plans?search=Étage')->assertJsonCount(1, 'data');

    // Le technicien : seulement les chantiers de ses tâches
    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/plans')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sous-sol')->assertJsonPath('data.0.markers', []);

    Sanctum::actingAs(userWithRole('accountant'));
    $this->getJson('/api/v1/plans')->assertForbidden();
});
