<?php

use App\Models\Article;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('donne aux repères l\'icône de l\'article, leur calque, taille et rotation', function () {
    $manager = userWithRole('manager');
    $project = Project::factory()->create();
    Sanctum::actingAs($manager);

    $icon = $this->postJson('/api/v1/plan-icons', ['name' => 'Caméra dôme', 'category' => 'Vidéo', 'symbol' => 'dome', 'color' => '#2563eb'])
        ->assertCreated()->assertJsonPath('data.symbol', 'dome')->json('data');
    $this->postJson('/api/v1/plan-icons', ['name' => 'Rien'])->assertStatus(422);
    $article = Article::factory()->create(['designation' => 'Dôme 4 MP']);
    $this->patchJson("/api/v1/articles/{$article->id}", ['plan_icon_id' => $icon['id']])->assertOk()->assertJsonPath('data.plan_icon_id', $icon['id']);

    $systemId = $this->postJson("/api/v1/projects/{$project->id}/installed-systems", ['name' => 'Vidéo'])->json('data.systems.0.id');
    $cameraId = $this->postJson("/api/v1/installed-systems/{$systemId}/equipment", ['name' => 'Dôme hall', 'article_id' => $article->id])->json('data.systems.0.equipment.0.id');
    $plan = $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->image('rdc.png', 800, 600)], ['Accept' => 'application/json'])->json('data');

    $marker = $this->postJson("/api/v1/plans/{$plan['id']}/markers", ['x' => 0.3, 'y' => 0.4, 'equipment_id' => $cameraId, 'size' => 0.05, 'rotation' => 90])
        ->assertCreated()->json('data.markers.0');
    expect($marker)->toMatchArray(['icon_id' => $icon['id'], 'layer_id' => $systemId, 'size' => 0.05, 'rotation' => 90, 'show_label' => true]);

    $this->patchJson("/api/v1/plan-markers/{$marker['id']}", ['rotation' => -90, 'show_label' => false])
        ->assertJsonPath('data.markers.0.rotation', 270)->assertJsonPath('data.markers.0.show_label', false);
    $this->patchJson("/api/v1/plan-markers/{$marker['id']}", ['size' => 2])->assertJsonValidationErrors('size');

    $this->patchJson("/api/v1/plans/{$plan['id']}", ['settings' => ['hidden_layers' => [(string) $systemId], 'legend' => false]])
        ->assertJsonPath('data.settings.hidden_layers', [(string) $systemId])->assertJsonPath('data.settings.legend', false);
});

it('garde l\'aperçu fait par l\'éditeur et le met dans le PDF du plan et le DOE', function () {
    $manager = userWithRole('manager');
    $project = Project::factory()->create();
    Sanctum::actingAs($manager);
    $plan = $this->post("/api/v1/projects/{$project->id}/plans", ['file' => UploadedFile::fake()->create('rdc.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'pdf')->json('data');

    $this->get("/api/v1/plans/{$plan['id']}/render/1", ['Accept' => 'application/json'])->assertNotFound();
    $this->post("/api/v1/plans/{$plan['id']}/render", ['file' => UploadedFile::fake()->image('render.png', 1200, 800), 'page' => 1, 'width' => 1200, 'height' => 800], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.renders.0.path', "plans/{$plan['id']}/render/1");
    $this->get("/api/v1/plans/{$plan['id']}/render/1")->assertOk();

    $this->get("/api/v1/plans/{$plan['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(\App\Support\QuotePdf::planPages(\App\Models\Plan::find($plan['id'])))->toHaveCount(1);
});

it('refuse une icône SVG qui contient du code et réserve la gestion de la bibliothèque', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="4"/></svg>');
    $this->post('/api/v1/plan-icons', ['name' => 'X', 'file' => $svg], ['Accept' => 'application/json'])->assertStatus(422);
    $ok = UploadedFile::fake()->image('siren.png', 64, 64);
    $id = $this->post('/api/v1/plan-icons', ['name' => 'Sirène', 'file' => $ok], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->get("/api/v1/plan-icons/{$id}/file")->assertOk();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/plan-icons')->assertOk();
    $this->postJson('/api/v1/plan-icons', ['name' => 'Y', 'symbol' => 'dot'])->assertForbidden();
});
