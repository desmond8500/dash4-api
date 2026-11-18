<?php

use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('crée un projet rattaché à un client', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);

    $this->postJson('/api/v1/projects', [
        'name' => 'Vidéosurveillance', 'client_id' => $client->id,
        'start_date' => '2026-12-01', 'end_date' => '2027-01-15',
        'address' => 'Dakar Plateau', 'latitude' => 14.67, 'longitude' => -17.43,
    ])->assertCreated()
        ->assertJsonPath('data.client.name', 'Hôtel Teranga')
        ->assertJsonPath('data.status.label', 'En cours')
        ->assertJsonPath('data.start_date', '2026-12-01')
        ->assertJsonPath('data.notes_count', 0)
        ->assertJsonPath('data.can_update', true);
});

it('exige un nom et un client, et des dates dans l\'ordre', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();

    $this->postJson('/api/v1/projects', [])
        ->assertJsonPath('errors.name.0', 'Le champ nom est obligatoire.')
        ->assertJsonValidationErrors(['client_id']);

    $this->postJson('/api/v1/projects', [
        'name' => 'X', 'client_id' => $client->id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-01',
    ])->assertJsonValidationErrors('end_date');

    // Modifier seulement la date de fin reste possible
    $project = Project::factory()->create(['client_id' => $client->id, 'start_date' => '2026-12-01']);
    $this->patchJson("/api/v1/projects/{$project->id}", ['end_date' => '2027-01-31'])->assertOk()->assertJsonPath('data.end_date', '2027-01-31');
});

it('ne crée pas de doublon quand le mobile renvoie le même projet', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/projects', ['client_uuid' => $uuid, 'name' => 'A', 'client_id' => $client->id])->assertCreated();
    $this->postJson('/api/v1/projects', ['client_uuid' => $uuid, 'name' => 'A', 'client_id' => $client->id])->assertOk();

    expect(Project::count())->toBe(1);
});

it('montre au chef de projet seulement ses projets, qu\'il peut modifier', function () {
    $chef = userWithRole('project_manager');
    $client = Client::factory()->create();
    $mine = Project::factory()->create(['client_id' => $client->id, 'manager_id' => $chef->id]);
    $other = Project::factory()->create(['client_id' => $client->id]);
    Sanctum::actingAs($chef);

    $this->getJson('/api/v1/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson("/api/v1/projects/{$other->id}")->assertForbidden();
    $this->patchJson("/api/v1/projects/{$mine->id}", ['status' => 'on_hold'])->assertOk()->assertJsonPath('data.status.label', 'En pause');
    $this->patchJson("/api/v1/projects/{$other->id}", ['status' => 'on_hold'])->assertForbidden();

    // Le projet qu'il crée lui est confié
    $this->postJson('/api/v1/projects', ['name' => 'Nouveau', 'client_id' => $client->id])
        ->assertCreated()->assertJsonPath('data.manager_id', $chef->id);
    $this->getJson('/api/v1/projects')->assertJsonCount(2, 'data');
});

it('laisse le comptable et le magasinier consulter sans modifier ; aucun projet pour le technicien pour l\'instant', function () {
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

    foreach (['accountant', 'storekeeper'] as $role) {
        Sanctum::actingAs(userWithRole($role));
        $this->getJson('/api/v1/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_update', false);
        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'])->assertForbidden();
    }

    // Le technicien verra les projets de ses tâches quand les tâches existeront
    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
});

it('réserve la suppression aux administrateurs et managers', function () {
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

    Sanctum::actingAs(userWithRole('sales'));
    $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();

    Sanctum::actingAs(userWithRole('manager'));
    $this->deleteJson("/api/v1/projects/{$project->id}")->assertOk();
    expect($project->fresh()->trashed())->toBeTrue();
});

it('filtre les projets actifs, par client, par recherche et les favoris en premier', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $teranga = Client::factory()->create(['name' => 'Hôtel Teranga']);
    $other = Client::factory()->create();
    Project::factory()->create(['name' => 'Caméras', 'client_id' => $teranga->id]);
    Project::factory()->create(['name' => 'Alarme', 'client_id' => $other->id, 'is_favorite' => true]);
    Project::factory()->create(['name' => 'Ancien', 'client_id' => $other->id, 'status' => 'completed']);

    $this->getJson('/api/v1/projects?active=1')->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Alarme');
    $this->getJson("/api/v1/projects?client_id={$teranga->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/projects?search=teranga')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Caméras');
    $this->getJson('/api/v1/projects?status=completed')->assertJsonCount(1, 'data');
});

it('renvoie les notes et liens dans la liste pour la synchro du mobile', function () {
    $sales = userWithRole('sales');
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $project->notes()->make(['body' => 'Code portail 1234'])->forceFill(['created_by' => $sales->id])->save();
    Sanctum::actingAs($sales);

    $this->getJson('/api/v1/projects?include=notes,links')
        ->assertJsonPath('data.0.notes.0.body', 'Code portail 1234')
        ->assertJsonPath('data.0.links', []);
    $this->getJson('/api/v1/projects')->assertJsonMissingPath('data.0.notes');
});

it('ajoute et retire le logo d\'un projet', function () {
    Storage::fake('public');
    Sanctum::actingAs(userWithRole('sales'));
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

    $url = $this->post("/api/v1/projects/{$project->id}/logo", ['logo' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])
        ->assertOk()->json('data.logo_url');
    expect($url)->toContain('projects/logos/');

    $this->deleteJson("/api/v1/projects/{$project->id}/logo")->assertJsonPath('data.logo_url', null);
});
