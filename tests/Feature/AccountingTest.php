<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Provider;
use App\Notifications\ExpenseNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('fait valider puis rembourser une dépense avancée par un technicien', function () {
    Notification::fake();
    Storage::fake('local');
    $tech = userWithRole('technician');
    $accountant = userWithRole('accountant');
    $site = Project::factory()->create();
    Sanctum::actingAs($tech);

    // Sur le terrain, une dépense se rattache à un projet ou à un devis
    $this->postJson('/api/v1/expenses', ['label' => 'Taxi', 'amount' => 5000, 'spent_at' => now()->toDateString()])
        ->assertStatus(422)->assertJsonPath('message', 'Liez la dépense à un projet ou à un devis.');

    $expense = $this->post('/api/v1/expenses', [
        'label' => 'Taxi chantier Almadies', 'amount' => 5000, 'spent_at' => now()->toDateString(), 'advanced' => '1', 'project_id' => $site->id,
        'payment_method' => 'cash', 'expense_category_id' => ExpenseCategory::where('name', 'Transport')->value('id'),
        'file' => UploadedFile::fake()->image('recu.jpg'), 'client_uuid' => (string) Str::uuid(),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($expense['status']['label'])->toBe('À valider')
        ->and($expense['category']['name'])->toBe('Transport')
        ->and($expense['document']['name'])->toBe('Justificatif — Taxi chantier Almadies.jpg')
        ->and($expense['can'])->toMatchArray(['update' => true, 'decide' => false]);
    Notification::assertSentTo($accountant, ExpenseNotification::class);
    $this->get("/api/v1/documents/{$expense['document']['id']}/download")->assertOk();
    $this->postJson("/api/v1/expenses/{$expense['id']}/approve")->assertForbidden();

    Sanctum::actingAs($accountant);
    $this->postJson("/api/v1/expenses/{$expense['id']}/reimburse")->assertStatus(422)->assertJsonPath('message', 'Validez la dépense avant de la rembourser.');
    $this->postJson("/api/v1/expenses/{$expense['id']}/approve")->assertJsonPath('data.to_reimburse', true);
    $this->getJson('/api/v1/expenses/summary')->assertJsonPath('data.to_reimburse_amount', 5000);
    $this->postJson("/api/v1/expenses/{$expense['id']}/reimburse")->assertJsonPath('data.status.label', 'Remboursée');
    Notification::assertSentTo($tech, ExpenseNotification::class, fn ($n) => $n->event === 'reimbursed');

    // Validée : son auteur ne la modifie plus
    Sanctum::actingAs($tech);
    $this->patchJson("/api/v1/expenses/{$expense['id']}", ['amount' => 9000])->assertForbidden();
});

it('valide d\'office les dépenses du comptable ; un refus demande un motif', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $mine = $this->postJson('/api/v1/expenses', ['label' => 'Carburant', 'amount' => 20000, 'spent_at' => now()->toDateString()])->json('data');
    expect($mine['status']['value'])->toBe('approved');

    $this->postJson("/api/v1/expenses/{$mine['id']}/reject")->assertJsonPath('errors.reason.0', 'Indiquez pourquoi la dépense est refusée.');
    $this->postJson("/api/v1/expenses/{$mine['id']}/reject", ['reason' => 'Doublon'])->assertJsonPath('data.status.label', 'Refusée');
});

it('ne montre à chacun que ses dépenses et celles de ses caisses', function () {
    $tech = userWithRole('technician');
    $other = userWithRole('technician');
    Expense::forceCreate(['label' => 'Autre', 'amount' => 1, 'spent_at' => now(), 'created_by' => $other->id]);
    Expense::forceCreate(['label' => 'La mienne', 'amount' => 1, 'spent_at' => now(), 'created_by' => $tech->id]);

    Sanctum::actingAs(userWithRole('accountant'));
    $space = $this->postJson('/api/v1/expense-spaces', ['name' => 'Caisse chantier', 'member_ids' => [$tech->id, $other->id]])->json('data');
    Expense::forceCreate(['label' => 'Caisse', 'amount' => 1, 'spent_at' => now(), 'created_by' => $other->id, 'expense_space_id' => $space['id']]);

    Sanctum::actingAs($tech);
    expect(collect($this->getJson('/api/v1/expenses')->json('data'))->pluck('label')->sort()->values()->all())->toBe(['Caisse', 'La mienne']);
    $this->getJson('/api/v1/accounting/summary')->assertForbidden();
    $this->getJson('/api/v1/transactions')->assertForbidden();
});

it('alimente une caisse et calcule son solde', function () {
    $tech = userWithRole('technician');
    Sanctum::actingAs(userWithRole('accountant'));
    $space = $this->postJson('/api/v1/expense-spaces', ['name' => 'Petite caisse', 'member_ids' => [$tech->id]])->assertCreated()->json('data');
    $this->postJson("/api/v1/expense-spaces/{$space['id']}/fundings", ['amount' => 100000, 'method' => 'cash'])
        ->assertJsonPath('data.balance', 100000)->assertJsonPath('data.fundings.0.method.label', 'Espèces');

    Sanctum::actingAs($tech);
    $base = ['spent_at' => now()->toDateString(), 'expense_space_id' => $space['id'], 'project_id' => Project::factory()->create()->id];
    $this->postJson('/api/v1/expenses', [...$base, 'label' => 'Câble', 'amount' => 30000])->assertCreated();
    $this->postJson('/api/v1/expenses', [...$base, 'label' => 'Avancé', 'amount' => 7000, 'advanced' => true])->assertCreated();
    $this->getJson("/api/v1/expense-spaces/{$space['id']}")->assertJson(['data' => ['funded' => 100000, 'spent' => 30000, 'pending' => 30000, 'balance' => 70000]]);

    $outsider = userWithRole('technician');
    Sanctum::actingAs($outsider);
    $this->postJson('/api/v1/expenses', [...$base, 'label' => 'X', 'amount' => 1])->assertStatus(422)->assertJsonPath('message', "Vous n'êtes pas membre de cette caisse.");
    $this->getJson("/api/v1/expense-spaces/{$space['id']}")->assertForbidden();
});

it('fait le bilan : paiements de factures, dépenses validées, achats reçus, transactions', function () {
    Sanctum::actingAs(userWithRole('admin'));
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    // Entrée : un paiement de facture
    $invoice = $this->postJson('/api/v1/invoices', ['client_id' => $client->id, 'title' => 'Maintenance'])->json('data');
    $this->postJson("/api/v1/invoices/{$invoice['id']}/lines", ['designation' => 'Visite', 'unit_price' => 200000]);
    Invoice::whereKey($invoice['id'])->update(['project_id' => $project->id]);
    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue");
    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 150000, 'method' => 'wave']);

    // Sorties : dépense validée, dépense à valider (ne compte pas), achat reçu avec TVA, transaction
    $this->postJson('/api/v1/expenses', ['label' => 'Carburant', 'amount' => 20000, 'spent_at' => now()->toDateString(), 'project_id' => $project->id]);
    Expense::forceCreate(['label' => 'En attente', 'amount' => 99999, 'spent_at' => now(), 'status' => 'submitted']);
    $purchase = $this->postJson('/api/v1/purchases', ['provider_id' => Provider::factory()->create()->id, 'tax_mode' => 'vat',
        'lines' => [['designation' => 'Câble', 'quantity' => 10, 'unit_cost' => 5000]]])->json('data');
    $this->postJson("/api/v1/purchases/{$purchase['id']}/order");
    $this->postJson("/api/v1/purchases/{$purchase['id']}/receive", ['lines' => [['id' => $purchase['lines'][0]['id'], 'quantity' => 4]]]);
    $this->postJson('/api/v1/transactions', ['direction' => 'out', 'label' => 'Loyer bureau', 'amount' => 100000, 'transacted_at' => now()->toDateString()])->assertCreated();
    $this->postJson('/api/v1/transactions', ['direction' => 'in', 'label' => 'Apport', 'amount' => 500000, 'transacted_at' => now()->toDateString()])->assertCreated();

    $summary = $this->getJson('/api/v1/accounting/summary')->assertOk()->json('data');
    expect($summary['in'])->toBe(650000)
        ->and($summary['out'])->toBe(20000 + 23600 + 100000)
        ->and($summary['net'])->toBe(650000 - 143600)
        ->and(collect($summary['by_category'])->firstWhere('category', 'Achats')['amount'])->toBe(23600);

    expect($this->getJson("/api/v1/accounting/summary?project_id={$project->id}")->json('data'))->toMatchArray(['in' => 150000, 'out' => 20000]);
    $this->getJson('/api/v1/accounting/ledger?direction=in')->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.source.type', fn ($t) => in_array($t, ['invoice', 'transaction'], true));
    // Recherche dans le journal : libellé, catégorie ou projet, accents ignorés
    $this->getJson('/api/v1/accounting/ledger?search=loyer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'Loyer bureau');
    $this->getJson('/api/v1/accounting/ledger?search=achats')->assertJsonCount(1, 'data');
});

it('reprend les dépenses du devis dans les dépenses', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'project_id' => $project->id, 'title' => 'Vidéo'])->json('data');
    $this->postJson("/api/v1/quotes/{$quote['id']}/expenses", ['label' => 'Caméras', 'amount' => 95000])->assertOk();

    $expense = Expense::firstOrFail();
    expect($expense)->toMatchArray(['quote_id' => $quote['id'], 'project_id' => $project->id, 'amount' => 95000])
        ->and($expense->status->value)->toBe('approved');

    // Une dépense refusée sort de la marge réelle du devis
    $expense->forceFill(['status' => 'rejected'])->save();
    $this->getJson("/api/v1/quotes/{$quote['id']}")->assertJsonPath('data.expenses_total', 0);
});

it('laisse le comptable choisir les membres d\'une caisse parmi les comptes actifs', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $this->getJson('/api/v1/assignees')->assertOk();
});

it('donne à une dépense liée à un devis le projet de ce devis', function () {
    $project = Project::factory()->create();
    $quote = \App\Models\Quote::factory()->create(['project_id' => $project->id, 'client_id' => $project->client_id]);
    Sanctum::actingAs(userWithRole('project_manager'));

    $this->postJson('/api/v1/expenses', ['label' => 'Câble', 'amount' => 8000, 'spent_at' => now()->toDateString(), 'quote_id' => $quote->id])
        ->assertCreated()->assertJsonPath('data.project_id', $project->id)->assertJsonPath('data.quote_id', $quote->id);
});
