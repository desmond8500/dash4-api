<?php

use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;

it('transforme une demande en projet et la marque traitée', function () {
    $sales = userWithRole('sales');
    $client = Client::factory()->create();
    $request = ClientRequest::factory()->create(['body' => 'Installer la vidéosurveillance de l\'entrepôt', 'client_id' => $client->id]);
    Sanctum::actingAs($sales);

    $response = $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project'])
        ->assertCreated()
        ->assertJsonPath('data.request.status.value', 'done')
        ->assertJsonPath('data.request.converted_to.type', 'project')
        ->assertJsonPath('data.project.client.id', $client->id)
        ->assertJsonPath('data.project.status.label', 'À venir');

    $project = Project::findOrFail($response->json('data.project.id'));
    expect($project->name)->toBe('Installer la vidéosurveillance de l\'entrepôt')
        ->and($project->description)->toBe($request->body)
        ->and($request->fresh()->converted_to_id)->toBe($project->id)
        ->and($request->fresh()->processed_by)->toBe($sales->id);
});

it('demande le client quand la demande n\'a qu\'un nom et un téléphone', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $request = ClientRequest::factory()->create(['contact_name' => 'Awa']);

    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project'])
        ->assertJsonValidationErrors('client_id');

    $client = Client::factory()->create();
    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project', 'client_id' => $client->id, 'name' => 'Interphone'])
        ->assertCreated()->assertJsonPath('data.project.name', 'Interphone');
});

it('ne transforme pas deux fois la même demande', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $request = ClientRequest::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project'])->assertCreated();
    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project'])
        ->assertStatus(422)->assertJsonPath('message', 'Cette demande a déjà été transformée.');

    expect(Project::count())->toBe(1);
});

it('refuse la transformation sans le droit de convertir', function () {
    $request = ClientRequest::factory()->create(['client_id' => Client::factory()->create()->id]);

    Sanctum::actingAs(userWithRole('accountant'));
    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'project'])->assertForbidden();

    expect(Project::count())->toBe(0);
});
