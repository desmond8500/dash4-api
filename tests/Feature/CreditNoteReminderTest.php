<?php

use App\Models\Client;
use App\Models\Email;
use App\Models\Invoice;
use App\Notifications\InvoiceReminderNotification;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function issuedInvoice(int $total = 100000, ?string $due = null, array $extra = []): Invoice
{
    $client = Client::factory()->create();
    $invoice = Invoice::query()->forceCreate([
        'number' => 'FAC-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT), 'client_id' => $client->id,
        'title' => 'Vidéosurveillance', 'status' => 'issued', 'issued_at' => now()->subDays(40)->toDateString(),
        'due_at' => $due ?? now()->subDays(10)->toDateString(), 'tax_mode' => 'none', 'subtotal' => $total, 'total' => $total, ...$extra,
    ]);
    $invoice->lines()->create(['designation' => 'Pose', 'quantity' => 1, 'unit_price' => $total, 'line_total' => $total]);

    return $invoice;
}

it('crée des avoirs qui soldent la facture, sans dépasser son total', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = issuedInvoice();

    $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['amount' => 30000, 'reason' => 'Caméra non posée'])
        ->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'AV-'));
    $this->getJson("/api/v1/invoices/{$invoice->id}")->assertJsonPath('data.credited_amount', 30000)
        ->assertJsonPath('data.remaining_amount', 70000)->assertJsonPath('data.status.value', 'partially_paid')
        ->assertJsonCount(1, 'data.credit_notes');
    $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['amount' => 80000, 'reason' => 'Trop'])->assertJsonValidationErrors('amount');
    // Sans montant : tout ce qui reste
    $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['reason' => 'Annulation du chantier'])->assertCreated()->assertJsonPath('data.amount', 70000);
    expect($invoice->refresh()->status->value)->toBe('paid')->and($invoice->remainingAmount())->toBe(0);
    $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['reason' => 'Encore'])->assertStatus(422);

    $note = $invoice->creditNotes()->first();
    expect(substr($this->get("/api/v1/credit-notes/{$note->id}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    Sanctum::actingAs(userWithRole('sales'));
    $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['reason' => 'x'])->assertForbidden();
});

it('relance une facture impayée à la main et signale les paliers chaque matin', function () {
    Mail::fake();
    Notification::fake();
    $accountant = userWithRole('accountant');
    $invoice = issuedInvoice(due: now()->subDays(8)->toDateString());

    Sanctum::actingAs($accountant);
    $draft = $this->getJson("/api/v1/invoices/{$invoice->id}/reminder-draft")->assertOk()->json('data');
    expect($draft['level'])->toBe(1)->and($draft['message'])->toContain('100 000');
    $this->postJson("/api/v1/invoices/{$invoice->id}/remind", ['to' => ['client@example.com'], 'subject' => $draft['subject'], 'message' => $draft['message']])
        ->assertOk()->assertJsonCount(1, 'data.reminders');

    // Palier 1 (7 jours) déjà relancé à la main : rien de plus ce matin
    $this->artisan('invoices:remind')->assertSuccessful();
    Notification::assertNothingSent();

    // Une autre facture à 16 jours, envoi automatique activé, client avec une adresse
    Settings::set(['reminders' => ['auto' => true, 'days' => [7, 15, 30]]]);
    $late = issuedInvoice(due: now()->subDays(16)->toDateString(), extra: ['issued_by' => $accountant->id]);
    Email::query()->forceCreate(['emailable_type' => 'client', 'emailable_id' => $late->client_id, 'address' => 'compta@client.sn']);
    $this->artisan('invoices:remind')->assertSuccessful();
    expect($late->refresh()->reminders()->count())->toBe(1)->and($late->reminders()->first()->automatic)->toBeTrue()
        ->and($late->reminder_level)->toBe(2);
    Notification::assertSentTo($accountant, InvoiceReminderNotification::class, fn ($n) => $n->sent && $n->invoice->is($late));
    // Le lendemain, même palier : rien
    $this->artisan('invoices:remind')->assertSuccessful();
    expect($late->reminders()->count())->toBe(1);
});

it('donne l\'échéancier des encaissements', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    issuedInvoice(50000, now()->addDays(5)->toDateString());
    issuedInvoice(20000, now()->subDays(3)->toDateString());
    $this->getJson('/api/v1/invoices/schedule')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 70000)->assertJsonPath('meta.overdue', 20000)
        ->assertJsonPath('data.0.amount', 20000);

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/invoices/schedule')->assertForbidden();
});
