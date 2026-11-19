<?php

use App\Models\Article;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('construit le chantier d\'un projet : bâtiments, niveaux, pièces, systèmes et équipements', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    $article = Article::factory()->create(['designation' => 'Caméra dôme 4 MP', 'unit_cost' => 50000]);

    Sanctum::actingAs($pm);
    $site = $this->postJson("/api/v1/projects/{$project->id}/buildings", ['name' => 'Bâtiment A'])->assertOk()->json('data');
    $buildingId = $site['buildings'][0]['id'];
    $site = $this->postJson("/api/v1/buildings/{$buildingId}/levels", ['name' => 'RDC'])->json('data');
    $levelId = $site['buildings'][0]['levels'][0]['id'];
    $site = $this->postJson("/api/v1/levels/{$levelId}/rooms", ['name' => 'Local technique'])->json('data');
    $roomId = $site['buildings'][0]['levels'][0]['rooms'][0]['id'];

    $site = $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Vidéosurveillance', 'building_id' => $buildingId])->assertOk()->json('data');
    $systemId = $site['systems'][0]['id'];
    $site = $this->postJson("/api/v1/installed-systems/{$systemId}/equipment", [
        'name' => 'Enregistreur 16 voies', 'type' => 'NVR', 'room_id' => $roomId, 'status' => 'installed',
        'characteristics' => [['label' => 'Adresse IP', 'value' => '192.168.1.10']],
    ])->assertCreated()->json('data');
    $nvr = $site['systems'][0]['equipment'][0];
    expect($nvr)->toMatchArray(['level_id' => $levelId, 'room_id' => $roomId])
        ->and($nvr['characteristics'][0])->toBe(['label' => 'Adresse IP', 'value' => '192.168.1.10'])
        ->and($nvr['status']['label'])->toBe('Posé');

    // Sous-équipement, depuis un article du stock : jamais de prix
    $site = $this->postJson("/api/v1/installed-systems/{$systemId}/equipment", ['article_id' => $article->id, 'parent_id' => $nvr['id'], 'quantity' => 8])->json('data');
    $camera = collect($site['systems'][0]['equipment'])->firstWhere('parent_id', $nvr['id']);
    expect($camera['name'])->toBe('Caméra dôme 4 MP')->and($camera['article'])->not->toHaveKey('unit_cost')
        ->and($site['equipment_count'])->toBe(9);

    // Pièce d'un autre projet refusée
    $other = Project::factory()->create(['manager_id' => $pm->id]);
    $otherBuilding = $this->postJson("/api/v1/projects/{$other->id}/buildings", ['name' => 'B'])->json('data.buildings.0.id');
    $otherLevel = $this->postJson("/api/v1/buildings/{$otherBuilding}/levels", ['name' => 'R+1'])->json('data.buildings.0.levels.0.id');
    $otherRoom = $this->postJson("/api/v1/levels/{$otherLevel}/rooms", ['name' => 'Bureau'])->json('data.buildings.0.levels.0.rooms.0.id');
    $this->patchJson("/api/v1/equipment/{$nvr['id']}", ['room_id' => $otherRoom])->assertStatus(422);

    // Avancement daté : celui du système est le relevé le plus récent
    $this->postJson("/api/v1/installed-systems/{$systemId}/progress", ['percent' => 40, 'date' => now()->subDays(3)->toDateString(), 'note' => 'Câblage'])->assertCreated();
    $site = $this->postJson("/api/v1/installed-systems/{$systemId}/progress", ['percent' => 75, 'note' => 'Caméras posées'])->json('data');
    expect($site['systems'][0]['progress'])->toBe(75)->and($site['progress'])->toBe(75)
        ->and($site['systems'][0]['progress_entries'])->toHaveCount(2);
    $this->postJson("/api/v1/installed-systems/{$systemId}/progress", ['percent' => 90, 'date' => now()->addDay()->toDateString()])->assertJsonValidationErrors('date');

    // Le chef de projet ne supprime pas (pas de sites.delete)
    $this->deleteJson("/api/v1/equipment/{$nvr['id']}")->assertForbidden();
    Sanctum::actingAs(userWithRole('manager'));
    $site = $this->deleteJson("/api/v1/equipment/{$nvr['id']}")->assertOk()->json('data');
    expect($site['systems'][0]['equipment'])->toBeEmpty();
});

it('laisse le technicien lire le chantier des projets de ses tâches, sans rien modifier', function () {
    $tech = userWithRole('technician');
    $project = Project::factory()->create();
    $elsewhere = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id])->assignees()->attach($tech->id);

    Sanctum::actingAs(userWithRole('manager'));
    $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Alarme intrusion'])->assertOk();

    Sanctum::actingAs($tech);
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertOk()
        ->assertJsonPath('data.systems.0.name', 'Alarme intrusion')->assertJsonPath('data.can.manage', false);
    $this->getJson("/api/v1/projects/{$elsewhere->id}/site")->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/buildings", ['name' => 'X'])->assertForbidden();

    // Le comptable n'a pas accès au chantier
    Sanctum::actingAs(userWithRole('accountant'));
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertForbidden();
});

it('rédige un procès-verbal de réception avec réserves, logos et PDF', function () {
    Storage::fake('public');
    Storage::fake('local');
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    Sanctum::actingAs($pm);
    $systemId = $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Détection incendie'])->json('data.systems.0.id');

    $report = $this->postJson("/api/v1/projects/{$project->id}/reception-reports", [
        'title' => 'Réception des travaux de sécurité incendie', 'date' => '2026-11-19', 'status' => 'with_reserves',
        'owner_name' => 'SCI Teranga', 'inspector_name' => 'Veritas',
        'reserves' => [['text' => 'Détecteur du R+1 à remplacer'], ['text' => 'Plan d\'évacuation manquant', 'lifted_at' => '2026-11-20']],
    ])->assertCreated()->json('data');
    expect($report['systems'])->toHaveCount(1)->and($report['systems'][0]['id'])->toBe($systemId)
        ->and($report['status']['label'])->toBe('Réception avec réserves')
        ->and(collect($report['parties'])->firstWhere('key', 'inspector')['name'])->toBe('Veritas');

    $this->post("/api/v1/reception-reports/{$report['id']}/logos/owner", ['logo' => UploadedFile::fake()->image('sci.png')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.parties.1.logo_url', fn ($url) => $url !== null);
    $this->post("/api/v1/reception-reports/{$report['id']}/logos/autre", ['logo' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertNotFound();
    expect(substr($this->get("/api/v1/reception-reports/{$report['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $this->getJson("/api/v1/projects/{$project->id}/reception-reports")->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertJsonPath('data.reception_reports_count', 1);
});

it('gère le catalogue des systèmes', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $id = $this->postJson('/api/v1/systems', ['name' => 'Contrôle d\'accès', 'icon' => 'pi-lock'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/systems')->assertJsonPath('data.0.name', 'Contrôle d\'accès');

    $project = Project::factory()->create();
    $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['system_id' => $id])->assertOk()
        ->assertJsonPath('data.systems.0.name', 'Contrôle d\'accès')->assertJsonPath('data.systems.0.system.icon', 'pi-lock');

    Sanctum::actingAs(userWithRole('technician'));
    $this->postJson('/api/v1/systems', ['name' => 'X'])->assertForbidden();
});
