<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
});

it('ajoute des notes à un projet, épinglées d\'abord, sans doublon hors ligne', function () {
    $sales = userWithRole('sales');
    Sanctum::actingAs($sales);
    $uuid = (string) Str::uuid();

    $this->postJson("/api/v1/projects/{$this->project->id}/notes", ['body' => 'Première'])->assertCreated()
        ->assertJsonPath('data.creator.id', $sales->id);
    $this->postJson("/api/v1/projects/{$this->project->id}/notes", ['body' => 'Code portail', 'is_pinned' => true, 'client_uuid' => $uuid])->assertCreated();
    $this->postJson("/api/v1/projects/{$this->project->id}/notes", ['body' => 'Code portail', 'client_uuid' => $uuid])->assertOk();

    $this->getJson("/api/v1/projects/{$this->project->id}/notes")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Code portail');

    $this->postJson("/api/v1/projects/{$this->project->id}/notes", [])
        ->assertJsonPath('errors.body.0', 'Le champ note est obligatoire.');
});

it('ajoute des notes à un client aussi', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    $this->postJson("/api/v1/clients/{$client->id}/notes", ['body' => 'Préfère être appelé le matin'])->assertCreated();
    $this->getJson("/api/v1/clients/{$client->id}/notes")->assertJsonCount(1, 'data');
});

it('refuse les types de fiche inconnus', function () {
    Sanctum::actingAs(userWithRole('admin'));

    $this->getJson('/api/v1/users/1/notes')->assertNotFound();
});

it('laisse l\'auteur modifier sa note ; un autre commercial ne peut pas, un manager peut supprimer', function () {
    $author = userWithRole('sales');
    Sanctum::actingAs($author);
    $id = $this->postJson("/api/v1/projects/{$this->project->id}/notes", ['body' => 'A'])->json('data.id');

    $this->patchJson("/api/v1/notes/{$id}", ['body' => 'A modifiée'])->assertOk()->assertJsonPath('data.body', 'A modifiée');

    Sanctum::actingAs(userWithRole('sales'));
    $this->patchJson("/api/v1/notes/{$id}", ['body' => 'B'])->assertForbidden();
    $this->deleteJson("/api/v1/notes/{$id}")->assertForbidden();

    Sanctum::actingAs(userWithRole('manager'));
    $this->deleteJson("/api/v1/notes/{$id}")->assertOk();
});

it('laisse le comptable lire les notes sans en ajouter', function () {
    Sanctum::actingAs(userWithRole('accountant'));

    $this->getJson("/api/v1/projects/{$this->project->id}/notes")->assertOk();
    $this->postJson("/api/v1/projects/{$this->project->id}/notes", ['body' => 'x'])->assertForbidden();
});

it('n\'accepte que des liens web (http ou https)', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $this->postJson("/api/v1/projects/{$this->project->id}/links", ['label' => 'Dossier', 'url' => 'https://drive.example.com/x'])
        ->assertCreated()->assertJsonPath('data.label', 'Dossier');

    foreach (['javascript:alert(1)', 'file:///etc/passwd', 'pas une adresse'] as $url) {
        $this->postJson("/api/v1/projects/{$this->project->id}/links", ['url' => $url])->assertJsonValidationErrors('url');
    }
});

it('stocke un document en privé et ne le donne qu\'à ceux qui voient la fiche', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $response = $this->post("/api/v1/projects/{$this->project->id}/documents", [
        'file' => UploadedFile::fake()->create('plan.pdf', 300, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.name', 'plan.pdf')
        ->assertJsonPath('data.size', 300 * 1024);

    $document = Document::findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists($document->path);
    expect($document->path)->toStartWith("documents/projects/{$this->project->id}/");

    $this->get("/api/v1/documents/{$document->id}/download")->assertOk()->assertDownload('plan.pdf');

    $chef = userWithRole('project_manager');
    Sanctum::actingAs($chef);
    $this->getJson("/api/v1/documents/{$document->id}/download")->assertForbidden();
});

it('refuse les fichiers dangereux et trop lourds', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $url = "/api/v1/projects/{$this->project->id}/documents";

    foreach (['virus.exe', 'page.html', 'image.svg', 'script.php'] as $name) {
        $this->post($url, ['file' => UploadedFile::fake()->create($name, 10)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }

    $this->post($url, ['file' => UploadedFile::fake()->create('gros.pdf', 25000)], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
});

it('garde le fichier tant que le document est dans la corbeille', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $id = $this->post("/api/v1/projects/{$this->project->id}/documents", [
        'file' => UploadedFile::fake()->create('devis.pdf', 10),
    ], ['Accept' => 'application/json'])->json('data.id');
    $path = Document::findOrFail($id)->path;

    $this->deleteJson("/api/v1/documents/{$id}")->assertOk();
    Storage::disk('local')->assertExists($path);

    Document::withTrashed()->findOrFail($id)->forceDelete();
    Storage::disk('local')->assertMissing($path);
});
