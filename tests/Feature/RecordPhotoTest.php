<?php

use App\Models\Article;
use App\Models\Tool;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('donne une photo au véhicule et la remplace, puis la retire', function () {
    Storage::fake('public');
    Sanctum::actingAs(userWithRole('manager'));
    $vehicle = Vehicle::query()->forceCreate(['name' => 'Berlingo']);

    $first = $this->post("/api/v1/vehicles/{$vehicle->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')])->assertOk()->json('data.photo_url');
    expect($first)->not->toBeNull();
    $old = $vehicle->refresh()->photo_path;
    $this->post("/api/v1/vehicles/{$vehicle->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg')])->assertOk();
    Storage::disk('public')->assertMissing($old);

    $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/photo")->assertOk()->assertJsonPath('data.photo_url', null);
    $this->post("/api/v1/vehicles/{$vehicle->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
});

it('laisse le conducteur photographier son véhicule (photo et galerie), pas les autres', function () {
    Storage::fake('public');
    Storage::fake('local');
    $tech = userWithRole('technician');
    $mine = Vehicle::query()->forceCreate(['name' => 'Moto Awa', 'driver_id' => $tech->id]);
    $other = Vehicle::query()->forceCreate(['name' => 'Pick-up']);
    Sanctum::actingAs($tech);

    $this->post("/api/v1/vehicles/{$mine->id}/photo", ['photo' => UploadedFile::fake()->image('moto.jpg')])->assertOk()->assertJsonPath('data.can.add_photos', true);
    $this->post("/api/v1/vehicles/{$mine->id}/documents", ['file' => UploadedFile::fake()->image('rayure.jpg')])->assertCreated();
    $this->post("/api/v1/vehicles/{$other->id}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    $this->patchJson("/api/v1/vehicles/{$mine->id}", ['name' => 'X'])->assertForbidden();
});

it('montre la photo de l\'article tant que l\'outil n\'a pas la sienne', function () {
    Storage::fake('public');
    Sanctum::actingAs(userWithRole('storekeeper'));
    $article = Article::factory()->create();
    $article->forceFill(['image_path' => 'articles/images/perceuse.jpg'])->save();
    $tool = Tool::query()->forceCreate(['name' => 'Perceuse', 'article_id' => $article->id]);

    $json = $this->getJson("/api/v1/tools/{$tool->id}")->json('data');
    expect($json['photo_url'])->toBeNull()->and($json['image_url'])->toEndWith('articles/images/perceuse.jpg');

    $photo = $this->post("/api/v1/tools/{$tool->id}/photo", ['photo' => UploadedFile::fake()->image('outil.jpg')])->assertOk()->json('data');
    expect($photo['image_url'])->toBe($photo['photo_url'])->not->toBeNull();
});
