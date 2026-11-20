<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Support\ContractVisits;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

it('répartit les visites d\'un contrat dans l\'année et les ajoute une fois au planning', function () {
    $this->travelTo(Carbon::parse('2026-03-25'));
    $client = Client::factory()->create();
    $contract = Contract::query()->forceCreate([
        'number' => 'CTR-2026-0001', 'client_id' => $client->id, 'title' => 'Maintenance alarme', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'billing_period' => 'quarterly', 'visits_per_year' => 4,
    ]);

    $dates = collect(ContractVisits::dates($contract, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')))->map(fn ($v) => $v['date']->toDateString());
    expect($dates->all())->toBe(['2026-01-01', '2026-04-01', '2026-07-01', '2026-10-01']);

    $this->artisan('contracts:check')->assertSuccessful();
    $visit = $contract->visits()->with('task')->sole();
    expect($visit->number)->toBe(2)->and($visit->planned_on->toDateString())->toBe('2026-04-01')
        ->and($visit->task->title)->toBe('Visite de maintenance 2/4 — Maintenance alarme')
        ->and($visit->task->client_id)->toBe($client->id);

    $this->artisan('contracts:check')->assertSuccessful();
    expect($contract->visits()->count())->toBe(1);

    Sanctum::actingAs(userWithRole('manager'));
    $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.visits.0.task.status.value', 'todo');
});

it('rattache un article à une dépense', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $article = Article::factory()->create();
    $this->postJson('/api/v1/expenses', ['label' => 'Câble', 'amount' => 5000, 'spent_at' => '2026-11-20', 'article_id' => $article->id])
        ->assertCreated()->assertJsonPath('data.article.designation', $article->designation);
    $this->postJson('/api/v1/expenses', ['label' => 'Câble', 'amount' => 5000, 'spent_at' => '2026-11-20', 'article_id' => 999999])
        ->assertJsonValidationErrors('article_id');
});

it('imprime le résumé d\'un projet, sans montants pour qui ne voit pas les prix', function () {
    $project = Project::factory()->create(['name' => 'Tour Diamniadio']);
    Sanctum::actingAs(userWithRole('manager'));
    expect(substr($this->get("/api/v1/projects/{$project->id}/summary-pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);
    $this->get("/api/v1/projects/{$project->id}/summary-pdf")->assertForbidden();
});
