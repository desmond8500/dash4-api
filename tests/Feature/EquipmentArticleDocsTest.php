<?php

use App\Models\Article;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('laisse un technicien consulter les documents, liens et notes d\'un article, sans prix ni droit de modifier', function () {
    Storage::fake('local');
    $article = Article::factory()->create(['designation' => 'Détecteur de fumée']);

    Sanctum::actingAs(userWithRole('manager'));
    $this->postJson("/api/v1/articles/{$article->id}/notes", ['body' => 'Tester tous les ans'])->assertCreated();
    $this->postJson("/api/v1/articles/{$article->id}/links", ['url' => 'https://example.com/notice', 'label' => 'Notice'])->assertCreated();
    $this->post("/api/v1/articles/{$article->id}/documents", [
        'file' => UploadedFile::fake()->create('fiche.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson("/api/v1/articles/{$article->id}")->assertOk()
        ->assertJsonPath('data.designation', 'Détecteur de fumée')
        ->assertJsonMissingPath('data.unit_price')
        ->assertJsonMissingPath('data.unit_cost');
    $this->getJson("/api/v1/articles/{$article->id}/notes")->assertOk()->assertJsonPath('data.0.body', 'Tester tous les ans');
    $this->getJson("/api/v1/articles/{$article->id}/links")->assertOk()->assertJsonPath('data.0.label', 'Notice');
    $this->getJson("/api/v1/articles/{$article->id}/documents")->assertOk()->assertJsonPath('data.0.name', 'fiche.pdf');

    $this->postJson("/api/v1/articles/{$article->id}/notes", ['body' => 'Non'])->assertForbidden();
});
