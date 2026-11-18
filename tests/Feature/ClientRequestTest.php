<?php

use App\Models\Client;
use App\Models\ClientRequest;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('note une demande avec seulement son texte (saisie express)', function () {
    $sales = userWithRole('sales');
    Sanctum::actingAs($sales);

    $this->postJson('/api/v1/client-requests', ['body' => 'Rappeler M. Diop pour la caméra du portail'])
        ->assertCreated()
        ->assertJsonPath('data.status.value', 'new')
        ->assertJsonPath('data.status.label', 'Nouvelle')
        ->assertJsonPath('data.source.label', 'Appel')
        ->assertJsonPath('data.priority.label', 'Normale')
        ->assertJsonPath('data.creator.id', $sales->id)
        ->assertJsonPath('data.is_overdue', false);
});

it('accepte un client existant, ou un simple nom et téléphone', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);

    $this->postJson('/api/v1/client-requests', ['body' => 'Devis alarme', 'client_id' => $client->id, 'source' => 'whatsapp'])
        ->assertCreated()
        ->assertJsonPath('data.client.name', 'Hôtel Teranga')
        ->assertJsonPath('data.source.label', 'WhatsApp');

    $this->postJson('/api/v1/client-requests', [
        'body' => 'Visite pour un interphone', 'contact_name' => 'Awa Ndiaye', 'contact_phone' => '77 123 45 67',
    ])->assertCreated()->assertJsonPath('data.contact_name', 'Awa Ndiaye')->assertJsonPath('data.client', null);
});

it('exige le texte de la demande, avec un message en français', function () {
    Sanctum::actingAs(userWithRole('sales'));

    $this->postJson('/api/v1/client-requests', ['source' => 'fax'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.body.0', 'Le champ demande est obligatoire.')
        ->assertJsonValidationErrors('source');
});

it('ne crée pas de doublon quand le mobile renvoie la même demande (client_uuid)', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/client-requests', ['client_uuid' => $uuid, 'body' => 'A'])->assertCreated();
    $this->postJson('/api/v1/client-requests', ['client_uuid' => $uuid, 'body' => 'A'])->assertOk();

    expect(ClientRequest::count())->toBe(1);
});

it('laisse le technicien noter une demande et ne voir que les siennes', function () {
    $tech = userWithRole('technician');
    $manager = userWithRole('manager');
    $other = ClientRequest::factory()->create(['created_by' => $manager->id]);
    $assigned = ClientRequest::factory()->create(['created_by' => $manager->id, 'assigned_to' => $tech->id]);
    Sanctum::actingAs($tech);

    // Le technicien ne fixe ni le statut, ni la personne en charge, ni le rappel
    $id = $this->postJson('/api/v1/client-requests', [
        'body' => 'Le client veut une prise en plus', 'status' => 'done', 'assigned_to' => $tech->id, 'priority' => 'high',
    ])->assertCreated()
        ->assertJsonPath('data.status.value', 'new')
        ->assertJsonPath('data.assigned_to', null)
        ->assertJsonPath('data.priority.value', 'high')
        ->json('data.id');

    $ids = collect($this->getJson('/api/v1/client-requests')->assertOk()->json('data'))->pluck('id');
    expect($ids->sort()->values()->all())->toBe(collect([$id, $assigned->id])->sort()->values()->all());

    $this->getJson("/api/v1/client-requests/{$other->id}")->assertForbidden();
    $this->patchJson("/api/v1/client-requests/{$id}", ['status' => 'done'])->assertForbidden();
    $this->deleteJson("/api/v1/client-requests/{$id}")->assertForbidden();
});

it('laisse le comptable consulter sans modifier, et ferme le module au magasinier', function () {
    $request = ClientRequest::factory()->create();

    Sanctum::actingAs(userWithRole('accountant'));
    $this->getJson('/api/v1/client-requests')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/client-requests', ['body' => 'x'])->assertForbidden();
    $this->patchJson("/api/v1/client-requests/{$request->id}", ['status' => 'done'])->assertForbidden();

    Sanctum::actingAs(userWithRole('storekeeper'));
    $this->getJson('/api/v1/client-requests')->assertForbidden();
});

it('réserve la suppression aux administrateurs et managers', function () {
    $request = ClientRequest::factory()->create();

    Sanctum::actingAs(userWithRole('sales'));
    $this->deleteJson("/api/v1/client-requests/{$request->id}")->assertForbidden();

    Sanctum::actingAs(userWithRole('manager'));
    $this->deleteJson("/api/v1/client-requests/{$request->id}")->assertOk();
    expect($request->fresh()->trashed())->toBeTrue();
});

it('filtre la boîte « À traiter », les demandes confiées et la recherche', function () {
    $sales = userWithRole('sales');
    $client = Client::factory()->create(['name' => 'Résidence Baobab']);
    ClientRequest::factory()->create(['body' => 'Alarme', 'client_id' => $client->id, 'assigned_to' => $sales->id]);
    ClientRequest::factory()->create(['body' => 'Caméra', 'status' => 'in_progress']);
    ClientRequest::factory()->create(['body' => 'Ancienne', 'status' => 'done']);
    ClientRequest::factory()->create(['body' => 'Sans suite', 'status' => 'dismissed']);
    Sanctum::actingAs($sales);

    $this->getJson('/api/v1/client-requests?open=1')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/client-requests?status=done,dismissed')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/client-requests?assigned=me')->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Alarme');
    $this->getJson('/api/v1/client-requests?search=baobab')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/client-requests?client_id={$client->id}")->assertJsonCount(1, 'data');
});

it('met les demandes urgentes en premier', function () {
    Sanctum::actingAs(userWithRole('sales'));
    ClientRequest::factory()->create(['body' => 'Normale']);
    ClientRequest::factory()->create(['body' => 'Urgente', 'priority' => 'high']);
    ClientRequest::factory()->create(['body' => 'Basse', 'priority' => 'low']);

    expect(collect($this->getJson('/api/v1/client-requests')->json('data'))->pluck('body')->all())
        ->toBe(['Urgente', 'Normale', 'Basse']);
});

it('note qui a traité la demande et quand, et efface si elle est rouverte', function () {
    $sales = userWithRole('sales');
    $request = ClientRequest::factory()->create();
    Sanctum::actingAs($sales);

    $this->patchJson("/api/v1/client-requests/{$request->id}", ['status' => 'done'])
        ->assertOk()
        ->assertJsonPath('data.status.label', 'Traitée')
        ->assertJsonPath('data.processor.id', $sales->id);
    expect($request->fresh()->processed_at)->not->toBeNull();

    $this->patchJson("/api/v1/client-requests/{$request->id}", ['status' => 'in_progress'])
        ->assertJsonPath('data.processed_at', null)
        ->assertJsonPath('data.processor', null);
});

it('confie une demande et fixe une date de rappel', function () {
    $sales = userWithRole('sales');
    $chef = userWithRole('project_manager');
    $request = ClientRequest::factory()->create();
    Sanctum::actingAs($sales);

    $this->patchJson("/api/v1/client-requests/{$request->id}", [
        'assigned_to' => $chef->id, 'remind_at' => '2026-11-20T09:00:00+00:00', 'priority' => 'high',
    ])->assertOk()
        ->assertJsonPath('data.assignee.id', $chef->id)
        ->assertJsonPath('data.priority.label', 'Urgente');

    $inactive = userWithRole('sales', ['is_active' => false]);
    $this->patchJson("/api/v1/client-requests/{$request->id}", ['assigned_to' => $inactive->id])
        ->assertJsonValidationErrors('assigned_to');
});

it('signale les demandes encore « Nouvelle » après 2 jours', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $old = ClientRequest::factory()->create(['created_at' => now()->subDays(3)]);
    ClientRequest::factory()->create(['created_at' => now()->subDay()]);
    ClientRequest::factory()->create(['created_at' => now()->subDays(5), 'status' => 'in_progress']);

    $this->getJson('/api/v1/client-requests?overdue=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $old->id)
        ->assertJsonPath('data.0.is_overdue', true);
});

it('donne les compteurs du tableau de bord', function () {
    $sales = userWithRole('sales');
    ClientRequest::factory()->create(['created_at' => now()->subDays(3)]);
    ClientRequest::factory()->create(['status' => 'in_progress', 'assigned_to' => $sales->id, 'remind_at' => now()->subHour()]);
    ClientRequest::factory()->create(['status' => 'done']);
    Sanctum::actingAs($sales);

    $this->getJson('/api/v1/client-requests/summary')->assertOk()->assertJson(['data' => [
        'open' => 2, 'new' => 1, 'overdue' => 1, 'mine' => 1, 'reminders_due' => 1, 'overdue_days' => 2,
    ]]);
});

it('synchronise seulement les changements, suppressions comprises', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $kept = ClientRequest::factory()->create(['updated_at' => now()->subDay()]);
    $since = now()->subHour()->toIso8601String();
    $changed = ClientRequest::factory()->create();
    $deleted = ClientRequest::factory()->create();
    $deleted->delete();

    $ids = collect($this->getJson('/api/v1/client-requests?updated_since='.urlencode($since))->json('data'))->pluck('id');

    expect($ids->all())->toContain($changed->id, $deleted->id)->not->toContain($kept->id);
});

it('liste les personnes à qui confier une demande, sans être gestionnaire des utilisateurs', function () {
    userWithRole('project_manager', ['firstname' => 'Fatou']);
    userWithRole('sales', ['firstname' => 'Inactif', 'is_active' => false]);

    Sanctum::actingAs(userWithRole('sales', ['firstname' => 'Moussa']));
    $names = collect($this->getJson('/api/v1/assignees')->assertOk()->json('data'))->pluck('full_name');
    expect($names->implode(' '))->toContain('Fatou')->toContain('Moussa')->not->toContain('Inactif');

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/assignees')->assertForbidden();
});
