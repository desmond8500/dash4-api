<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use Laravel\Sanctum\Sanctum;

it('montre au gestionnaire les chiffres de l\'entreprise', function () {
    $manager = userWithRole('manager');
    Article::factory()->create(['quantity' => 1, 'min_quantity' => 5]);
    $client = Client::factory()->create();
    $invoice = Invoice::query()->create(['client_id' => $client->id, 'title' => 'Facture']);
    $invoice->forceFill(['status' => 'issued', 'total' => 100000, 'paid_amount' => 40000, 'issued_at' => today(), 'due_at' => today()->subDay()])->save();
    $invoice->payments()->create(['amount' => 40000, 'paid_at' => today(), 'method' => 'cash']);

    Sanctum::actingAs($manager);
    $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
    expect($data['layout']['order'])->toContain('revenue', 'receivables', 'stock', 'contracts')
        ->and($data['widgets']['receivables'])->toMatchArray(['count' => 1, 'amount' => 60000, 'overdue_count' => 1])
        ->and($data['widgets']['revenue']['this_month'])->toBe(40000)
        ->and($data['widgets']['revenue']['series'])->toHaveCount(6)
        ->and($data['widgets']['stock']['low'])->toBe(1);

    // Masquer et réordonner
    $saved = $this->putJson('/api/v1/dashboard/layout', ['order' => ['stock', 'revenue'], 'hidden' => ['receivables']])->assertOk()->json('data');
    expect($saved['layout']['order'][0])->toBe('stock')->and($saved['widgets'])->not->toHaveKey('receivables');
    $this->putJson('/api/v1/dashboard/layout', ['order' => ['inconnu'], 'hidden' => []])->assertJsonValidationErrors('order.0');
});

it('ne donne au technicien que ses tâches, sans aucun montant', function () {
    $tech = userWithRole('technician');
    $task = Task::factory()->create(['project_id' => Project::factory()->create()->id, 'due_date' => today()->subDay(), 'title' => 'Pose détecteurs']);
    $task->assignees()->attach($tech->id);

    Sanctum::actingAs($tech);
    $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
    expect($data['widgets'])->not->toHaveKeys(['revenue', 'receivables', 'quotes', 'stock', 'contracts'])
        ->and($data['widgets']['my_tasks'])->toMatchArray(['open' => 1, 'overdue' => 1])
        ->and($data['widgets']['my_tasks']['items'][0]['title'])->toBe('Pose détecteurs');
    expect(json_encode($data))->not->toContain('amount');
});
