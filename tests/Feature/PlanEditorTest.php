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
        ->assertJsonPath('data.settings.hidden_layers', [(string) $systemId])->assertJsonPath('data.settings.legend', false)
        ->assertJsonPath('data.settings.legend_position', 'below');
    $this->patchJson("/api/v1/plans/{$plan['id']}", ['settings' => ['legend_position' => 'overlay', 'legend_x' => 0.05, 'legend_y' => 0.8, 'legend_scale' => 1.5]])
        ->assertJsonPath('data.settings.legend_position', 'overlay')->assertJsonPath('data.settings.legend_x', 0.05)
        ->assertJsonPath('data.settings.hidden_layers', [(string) $systemId]);
    $this->patchJson("/api/v1/plans/{$plan['id']}", ['settings' => ['legend_position' => 'top', 'legend_x' => 2]])->assertJsonValidationErrors(['settings.legend_position', 'settings.legend_x']);
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

    $this->postJson("/api/v1/plans/{$plan['id']}/markers", ['x' => 0.5, 'y' => 0.5, 'label' => 'Regard'])->assertCreated();
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

it('installe la bibliothèque standard de la légende sans écraser les changements', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $total = count(\App\Support\PlanIconLibrary::defaults());

    $this->postJson('/api/v1/plan-icons/install')->assertOk()->assertJsonPath('data.created', $total);
    $nc2 = $this->getJson('/api/v1/plan-icons')->assertJsonCount($total, 'data')->collect('data')->firstWhere('slug', 'nc2');
    expect($nc2)->toMatchArray(['code' => 'NC2', 'symbol' => 'dot', 'name' => 'Extincteur CO2 2 kg', 'category' => 'Extincteurs'])
        ->and($nc2['secondary_color'])->not->toBeNull();

    $this->patchJson("/api/v1/plan-icons/{$nc2['id']}", ['name' => 'CO2 2 kg', 'code' => 'CO2'])->assertJsonPath('data.code', 'CO2');
    $this->postJson('/api/v1/plan-icons/install')->assertJsonPath('data.created', 0)->assertJsonPath('data.unchanged', $total);
    $this->getJson('/api/v1/plan-icons')->assertJsonPath('data.'.collect($this->getJson('/api/v1/plan-icons')->json('data'))->search(fn ($i) => $i['slug'] === 'nc2').'.code', 'CO2');

    $this->postJson('/api/v1/plan-icons/install', ['reset' => true])->assertJsonPath('data.updated', $total);
    expect(\App\Models\PlanIcon::where('slug', 'nc2')->value('code'))->toBe('NC2');

    // Les codes tiennent dans la colonne (6 caractères) et les clés sont uniques
    $defaults = collect(\App\Support\PlanIconLibrary::defaults());
    expect($defaults->pluck('slug')->duplicates())->toBeEmpty()
        ->and($defaults->every(fn ($d) => mb_strlen($d['code'] ?? '') <= 6 && in_array($d['symbol'], \App\Models\PlanIcon::SYMBOLS, true)))->toBeTrue();
});
