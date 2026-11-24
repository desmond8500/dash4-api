<?php

use App\Models\Article;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Notifications\ToolLoanNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('crée des outils depuis un article, les prête, refuse un second prêt et enregistre le retour', function () {
    Notification::fake();
    $storekeeper = userWithRole('storekeeper');
    $tech = userWithRole('technician');
    $article = Article::factory()->create(['designation' => 'Perforateur SDS']);
    Sanctum::actingAs($storekeeper);

    // Trois exemplaires d'un coup : nom repris de l'article, numéros suffixés
    $tools = $this->postJson('/api/v1/tools', ['article_id' => $article->id, 'code' => 'OUT-PERF', 'count' => 3, 'purchase_value' => 85000])
        ->assertCreated()->assertJsonCount(3, 'data')->json('data');
    expect(array_column($tools, 'code'))->toBe(['OUT-PERF-01', 'OUT-PERF-02', 'OUT-PERF-03'])
        ->and($tools[0]['name'])->toBe('Perforateur SDS')
        ->and($tools[0]['status']['value'])->toBe('available');
    $id = $tools[0]['id'];

    $uuid = (string) Str::uuid();
    $this->postJson("/api/v1/tools/{$id}/loans", ['borrower_id' => $tech->id, 'due_on' => today()->addDays(3)->toDateString(), 'client_uuid' => $uuid])
        ->assertCreated()->assertJsonPath('data.status.value', 'lent')->assertJsonPath('data.current_loan.borrower.id', $tech->id);
    // Rejoué hors ligne : pas de doublon
    $this->postJson("/api/v1/tools/{$id}/loans", ['borrower_id' => $tech->id, 'client_uuid' => $uuid])->assertCreated();
    expect(ToolLoan::query()->count())->toBe(1);
    Notification::assertSentTo($tech, ToolLoanNotification::class, fn ($n) => $n->event === 'lent');

    $this->postJson("/api/v1/tools/{$id}/loans", ['borrower_name' => 'Sous-traitant Diop'])->assertStatus(422);
    $this->deleteJson("/api/v1/tools/{$id}")->assertStatus(422);
    $this->getJson('/api/v1/tools?status=lent')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/tool-loans?open=1')->assertJsonCount(1, 'data');

    $loan = ToolLoan::query()->first();
    $this->postJson("/api/v1/tools/{$id}/loans/{$loan->id}/return", ['condition' => 'repair', 'return_notes' => 'Mandrin abîmé'])
        ->assertOk()->assertJsonPath('data.status.value', 'unavailable')->assertJsonPath('data.loans.0.return_condition.value', 'repair');
    // Retour rejoué (file d'attente du téléphone) : rien ne change
    $this->postJson("/api/v1/tools/{$id}/loans/{$loan->id}/return", ['condition' => 'good'])
        ->assertOk()->assertJsonPath('data.condition.value', 'repair');
    // En réparation : on ne peut plus le prêter
    $this->postJson("/api/v1/tools/{$id}/loans", ['borrower_name' => 'Sous-traitant Diop'])->assertStatus(422);
});

it('montre au technicien les seuls outils qu\'il a en prêt, sans valeur d\'achat, et lui interdit de prêter', function () {
    $tech = userWithRole('technician');
    $mine = Tool::query()->forceCreate(['name' => 'Pince à sertir', 'code' => 'OUT-1', 'purchase_value' => 30000]);
    $other = Tool::query()->forceCreate(['name' => 'Escabeau', 'code' => 'OUT-2']);
    $mine->loans()->forceCreate(['tool_id' => $mine->id, 'borrower_id' => $tech->id, 'lent_at' => now()]);
    Sanctum::actingAs($tech);

    $this->getJson('/api/v1/tools')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    expect($this->getJson("/api/v1/tools/{$mine->id}")->assertOk()->json('data'))->not->toHaveKey('purchase_value');
    $this->getJson("/api/v1/tools/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/tools/{$other->id}/loans", ['borrower_id' => $tech->id])->assertForbidden();
    $this->postJson('/api/v1/tools', ['name' => 'Niveau laser'])->assertForbidden();
});

it('rappelle la veille, le jour même, puis en retard tous les 3 jours', function () {
    Notification::fake();
    $storekeeper = userWithRole('storekeeper');
    $tech = userWithRole('technician');
    $tool = Tool::query()->forceCreate(['name' => 'Perceuse', 'code' => 'OUT-9']);
    $loan = $tool->loans()->forceCreate(['tool_id' => $tool->id, 'borrower_id' => $tech->id, 'created_by' => $storekeeper->id, 'lent_at' => now()->subDays(5), 'due_on' => today()->addDay()]);

    $this->artisan('tools:remind')->assertSuccessful();
    $this->artisan('tools:remind')->assertSuccessful();
    Notification::assertSentToTimes($tech, ToolLoanNotification::class, 1);

    $loan->forceFill(['due_on' => today()])->save();
    $this->artisan('tools:remind');
    Notification::assertSentToTimes($tech, ToolLoanNotification::class, 2);

    // En retard : l'emprunteur et le magasinier (gestionnaire et prêteur) sont prévenus une fois
    $loan->forceFill(['due_on' => today()->subDay()])->save();
    $this->artisan('tools:remind');
    $this->artisan('tools:remind');
    Notification::assertSentToTimes($tech, ToolLoanNotification::class, 3);
    Notification::assertSentToTimes($storekeeper, ToolLoanNotification::class, 1);

    // 3 jours plus tard : nouveau rappel
    $loan->forceFill(['last_reminded_on' => today()->subDays(3)])->save();
    $this->artisan('tools:remind');
    Notification::assertSentToTimes($tech, ToolLoanNotification::class, 4);

    // Rendu : plus de rappel
    $loan->forceFill(['returned_at' => now(), 'last_reminded_on' => today()->subDays(10)])->save();
    $this->artisan('tools:remind');
    Notification::assertSentToTimes($tech, ToolLoanNotification::class, 4);
});
