<?php

use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

it('ajoute un logo au client et renvoie son adresse', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    $response = $this->post("/api/v1/clients/{$client->id}/logo", [
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ], ['Accept' => 'application/json']);

    $response->assertOk();
    $path = $client->refresh()->logo_path;
    expect($path)->toStartWith('clients/logos/');
    Storage::disk('public')->assertExists($path);
    expect($response->json('data.logo_url'))->toEndWith($path);
});

it('remplace l\'ancien logo et supprime son fichier', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    $this->post("/api/v1/clients/{$client->id}/logo", ['logo' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json']);
    $old = $client->refresh()->logo_path;
    $this->post("/api/v1/clients/{$client->id}/logo", ['logo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json']);

    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($client->refresh()->logo_path);
});

it('retire le logo', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $this->post("/api/v1/clients/{$client->id}/logo", ['logo' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json']);
    $path = $client->refresh()->logo_path;

    $this->deleteJson("/api/v1/clients/{$client->id}/logo")
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    Storage::disk('public')->assertMissing($path);
});

it('refuse un fichier qui n\'est pas une image JPEG, PNG ou WebP (SVG compris)', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    foreach ([
        UploadedFile::fake()->create('logo.pdf', 50, 'application/pdf'),
        UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    ] as $file) {
        $this->post("/api/v1/clients/{$client->id}/logo", ['logo' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    expect($client->refresh()->logo_path)->toBeNull();
});

it('refuse une image de plus de 2 Mo', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    $this->post("/api/v1/clients/{$client->id}/logo", [
        'logo' => UploadedFile::fake()->image('logo.png')->size(3000),
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors('logo');
});

it('réserve le changement de logo à ceux qui peuvent modifier les clients', function () {
    $client = Client::factory()->create();

    Sanctum::actingAs(userWithRole('accountant'));
    $this->post("/api/v1/clients/{$client->id}/logo", ['logo' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/clients/{$client->id}/logo")->assertForbidden();
});
