<?php

use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('crée des badges à partir d\'un modèle, importe une liste et imprime en PDF', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    $other = Project::factory()->create();
    Sanctum::actingAs($pm);

    $template = $this->postJson('/api/v1/badge-templates', ['name' => 'Badge ARP', 'primary_color' => '#0f766e', 'fields' => ['job_title', 'employee_number']])
        ->assertCreated()->json('data');
    $this->post("/api/v1/badge-templates/{$template['id']}/images/logo", ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.logo_url', fn ($u) => $u !== null);

    $badge = $this->postJson("/api/v1/projects/{$project->id}/badges", ['firstname' => 'Awa', 'lastname' => 'Ndiaye', 'job_title' => 'Directrice', 'badge_template_id' => $template['id']])
        ->assertCreated()->json('data');
    $this->post("/api/v1/badges/{$badge['id']}/photo", ['logo' => UploadedFile::fake()->image('awa.jpg', 300, 400)], ['Accept' => 'application/json'])->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/badges", ['badge_template_id' => $template['id'], 'badges' => [
        ['firstname' => 'Moussa', 'lastname' => 'Diop', 'employee_number' => 'M-002'],
        ['firstname' => 'Fatou', 'lastname' => 'Sall', 'employee_number' => 'M-003'],
    ]])->assertCreated()->assertJsonCount(3, 'data');

    expect(substr($this->get("/api/v1/projects/{$project->id}/badges/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
    expect(substr($this->get("/api/v1/projects/{$project->id}/badges/pdf?layout=sheet&ids={$badge['id']}")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
    $this->getJson("/api/v1/projects/{$project->id}/badges")->assertJsonPath('data.0.printed_at', fn ($v) => $v !== null);

    // Pas sur le projet d'un autre ; pas de suppression sans badges.delete
    $this->getJson("/api/v1/projects/{$other->id}/badges")->assertForbidden();
    $this->deleteJson("/api/v1/badges/{$badge['id']}")->assertForbidden();
    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson("/api/v1/projects/{$project->id}/badges")->assertForbidden();
});

it('facture un contrat de maintenance période par période', function () {
    $this->travelTo(now()->setDate(2026, 11, 19));
    $manager = userWithRole('manager');
    $client = Client::factory()->create();
    Sanctum::actingAs($manager);

    $rate = $this->postJson('/api/v1/flat-rates', ['designation' => 'Maintenance vidéosurveillance', 'price' => 75000, 'unit' => 'mois'])->assertCreated()->json('data');
    $contract = $this->postJson('/api/v1/contracts', [
        'client_id' => $client->id, 'title' => 'Maintenance sécurité', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        'billing_period' => 'quarterly', 'visits_per_year' => 4, 'tacit_renewal' => true,
        'lines' => [['flat_rate_id' => $rate['id'], 'quantity' => 3], ['designation' => 'Astreinte', 'quantity' => 1, 'unit_price' => 30000]],
    ])->assertCreated()->json('data');
    expect($contract['number'])->toStartWith('CTR-2026-')->and($contract['period_amount'])->toEqual(255000)
        ->and($contract['lines'][0]['designation'])->toBe('Maintenance vidéosurveillance')->and($contract['invoice_due'])->toBeTrue();

    $invoice = $this->postJson("/api/v1/contracts/{$contract['id']}/invoice")->assertCreated()->json('data');
    expect($invoice['lines'])->toHaveCount(2)->and($invoice['title'])->toContain('du 01/11/2026 au 31/01/2027');
    $this->postJson("/api/v1/contracts/{$contract['id']}/invoice")->assertCreated();
    $again = $this->getJson("/api/v1/contracts/{$contract['id']}")->json('data');
    expect($again['next_invoice_date'])->toBe('2027-05-01')->and($again['invoices'])->toHaveCount(2)->and($again['invoice_due'])->toBeFalse();
    expect(substr($this->get("/api/v1/contracts/{$contract['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    // Le chef de projet lit les contrats ; le technicien n'y a pas accès
    Sanctum::actingAs(userWithRole('project_manager'));
    $this->getJson('/api/v1/contracts')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/contracts', ['client_id' => $client->id, 'title' => 'X', 'start_date' => '2026-11-01'])->assertForbidden();
    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/contracts')->assertForbidden();
});

it('prévient de l\'échéance et reconduit tacitement les contrats', function () {
    $this->travelTo(now()->setDate(2026, 11, 19));
    $manager = userWithRole('manager');
    $renewed = Contract::query()->create(['client_id' => Client::factory()->create()->id, 'title' => 'A', 'status' => 'active', 'start_date' => '2025-11-01', 'end_date' => '2026-10-31', 'tacit_renewal' => true]);
    $ended = Contract::query()->create(['client_id' => Client::factory()->create()->id, 'title' => 'B', 'status' => 'active', 'start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
    $ending = Contract::query()->create(['client_id' => Client::factory()->create()->id, 'title' => 'C', 'status' => 'active', 'start_date' => '2025-12-01', 'end_date' => '2026-12-10', 'notice_days' => 30]);

    $this->artisan('contracts:check')->assertSuccessful();
    expect($renewed->refresh()->end_date->toDateString())->toBe('2027-10-31')
        ->and($ended->refresh()->status->value)->toBe('ended')
        ->and($ending->refresh()->end_notified_at)->not->toBeNull()
        ->and($manager->notifications()->where('data->kind', 'contract.ending')->count())->toBe(1);
    $this->artisan('contracts:check')->assertSuccessful();
    expect($manager->notifications()->where('data->kind', 'contract.ending')->count())->toBe(1);
});
