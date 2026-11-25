<?php

use App\Mail\QuoteMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * Devis accepté, TVA 18 % : centrale 210 000, 2 caméras 150 000, câble 60 000 (matériel 420 000),
 * accessoires 20 000, pose 100 000 (main-d'œuvre) — total HT 540 000.
 */
function acceptedQuote(array $header = []): Quote
{
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);
    $quote = test()->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Vidéosurveillance', 'tax_mode' => 'vat', ...$header])->json('data');
    $section = $quote['sections'][0]['id'];
    foreach ([
        ['designation' => 'Enregistreur', 'category' => 'central', 'unit_cost' => 150000, 'coefficient' => 1.4],
        ['designation' => 'Caméra', 'category' => 'device', 'quantity' => 2, 'unit_cost' => 50000, 'coefficient' => 1.5],
        ['designation' => 'Câble', 'category' => 'cable', 'quantity' => 100, 'unit_cost' => 300, 'coefficient' => 2],
        ['designation' => 'Connecteurs', 'category' => 'accessory', 'unit_cost' => 20000],
        ['designation' => 'Pose', 'category' => 'service', 'unit_cost' => 100000],
    ] as $line) {
        test()->postJson("/api/v1/quote-sections/{$section}/lines", $line)->assertOk();
    }
    test()->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])->assertOk();

    return Quote::findOrFail($quote['id']);
}

function newInvoice(Quote $quote, array $data): array
{
    return test()->postJson('/api/v1/invoices', ['quote_id' => $quote->id, ...$data])->assertCreated()->json('data');
}

it('facture tout le devis à 100 % en reprenant ses lignes', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));

    $invoice = newInvoice($quote, ['scope' => 'all']);

    expect($invoice['status']['label'])->toBe('Brouillon')
        ->and($invoice['number'])->toBeNull()
        ->and($invoice['title'])->toBe('Facture')
        ->and($invoice['lines'])->toHaveCount(5)
        ->and($invoice['subtotal'])->toBe(540000)
        ->and($invoice['tax_amount'])->toBe(97200)
        ->and($invoice['total'])->toBe(637200)
        ->and($invoice['introduction'])->toContain($quote->number);
});

it('fait des factures d\'acompte sur le matériel, le matériel et les accessoires, la main-d\'œuvre', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));

    $equipment = newInvoice($quote, ['scope' => 'equipment', 'percent' => 50]);
    expect($equipment['title'])->toBe("Facture d'acompte de 50 % — matériel")
        ->and($equipment['lines'])->toHaveCount(1)
        ->and($equipment['lines'][0]['designation'])->toBe("Acompte de 50 % sur matériel du devis {$quote->number}")
        ->and($equipment['subtotal'])->toBe(210000);

    expect(newInvoice($quote, ['scope' => 'equipment_accessories', 'percent' => 100])['subtotal'])->toBe(440000);
    expect(newInvoice($quote, ['scope' => 'labor', 'percent' => 100, 'title' => 'Facture pose'])['title'])->toBe('Facture pose');
    expect(newInvoice($quote, ['scope' => 'all', 'percent' => 30])['title'])->toBe("Facture d'acompte de 30 %");
});

it('facture le reliquat : tout le devis moins ce qui est déjà facturé', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));
    $first = newInvoice($quote, ['scope' => 'equipment', 'percent' => 50]);
    $this->postJson("/api/v1/invoices/{$first['id']}/issue")->assertOk();

    $rest = newInvoice($quote, ['scope' => 'remainder']);

    expect($rest['title'])->toBe('Facture de solde')
        ->and(collect($rest['lines'])->last()['designation'])->toStartWith('Déjà facturé : FAC-')
        ->and($rest['subtotal'])->toBe(540000 - 210000);

    // Une fois tout facturé, plus de reliquat
    $this->postJson("/api/v1/invoices/{$rest['id']}/issue")->assertOk();
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote->id, 'scope' => 'remainder'])
        ->assertStatus(422)->assertJsonPath('message', 'Tout le devis est déjà facturé : pas de reliquat à facturer.');
    $this->getJson("/api/v1/quotes/{$quote->id}")->assertJsonPath('data.remaining_to_invoice', 0)->assertJsonCount(2, 'data.invoices');
});

it('reprend la remise du devis en ligne négative', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    $quote->update(['discount_rate' => 10]);
    Sanctum::actingAs(userWithRole('accountant'));

    $invoice = newInvoice($quote, ['scope' => 'all']);

    expect(collect($invoice['lines'])->last())->toMatchArray(['designation' => 'Remise de 10 %', 'unit_price' => -54000])
        ->and($invoice['subtotal'])->toBe(486000);
});

it('ne facture qu\'un devis accepté', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = Quote::factory()->create();
    Sanctum::actingAs(userWithRole('accountant'));

    $this->postJson('/api/v1/invoices', ['quote_id' => $quote->id])
        ->assertStatus(422)->assertJsonPath('message', 'Seul un devis accepté se facture (ce devis est « Brouillon »).');
});

it('facture un acompte prévu sur le devis, et marque l\'acompte payé avec la facture', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    $deposit = $this->postJson("/api/v1/quotes/{$quote->id}/deposits", ['label' => 'Acompte à la commande', 'percent' => 50])->json('data.deposits.0');
    Sanctum::actingAs(userWithRole('accountant'));

    $invoice = $this->postJson('/api/v1/invoices', ['quote_deposit_id' => $deposit['id']])->assertCreated()->json('data');
    expect($invoice['total'])->toBe($deposit['amount'])->and($invoice['title'])->toBe("Facture d'acompte — Acompte à la commande");
    $this->postJson('/api/v1/invoices', ['quote_deposit_id' => $deposit['id']])->assertStatus(422);

    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue")->assertOk();
    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => $invoice['total'], 'method' => 'wave'])->assertOk();

    expect($quote->deposits()->first()->is_paid)->toBeTrue();
});

it('numérote à l\'émission, sans trou, et verrouille la facture', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));
    $a = newInvoice($quote, ['scope' => 'equipment', 'percent' => 30]);
    $b = newInvoice($quote, ['scope' => 'labor']);

    // Le brouillon supprimé ne consomme pas de numéro
    $this->deleteJson("/api/v1/invoices/{$a['id']}")->assertOk();
    $issued = $this->postJson("/api/v1/invoices/{$b['id']}/issue", ['issued_at' => '2026-11-18'])->assertOk()->json('data');

    expect($issued['number'])->toBe('FAC-2026-0001')
        ->and($issued['status']['label'])->toBe('Émise')
        ->and($issued['due_at'])->toBe('2026-12-18')
        ->and($issued['can']['edit'])->toBeFalse();

    $this->patchJson("/api/v1/invoices/{$b['id']}", ['title' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Cette facture est « Émise » : elle ne se modifie plus.');
    $this->patchJson("/api/v1/invoices/{$b['id']}", ['notes' => 'Relancé par téléphone'])->assertOk();
    $this->deleteJson("/api/v1/invoices/{$b['id']}")->assertForbidden();
});

it('modifie les lignes d\'un brouillon, remise négative comprise', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $client = Client::factory()->create();
    $invoice = $this->postJson('/api/v1/invoices', ['client_id' => $client->id, 'title' => 'Maintenance annuelle'])->assertCreated()->json('data');
    expect($invoice['scope']['value'])->toBe('custom')->and($invoice['tax_mode']['value'])->toBe('none');

    $invoice = $this->postJson("/api/v1/invoices/{$invoice['id']}/lines", ['designation' => 'Visite', 'quantity' => 4, 'unit_price' => 25000])->json('data');
    $invoice = $this->postJson("/api/v1/invoices/{$invoice['id']}/lines", ['designation' => 'Geste commercial', 'unit_price' => -10000])->json('data');
    expect($invoice['total'])->toBe(90000);

    $invoice = $this->patchJson("/api/v1/invoices/{$invoice['id']}", ['tax_mode' => 'brs'])->json('data');
    expect($invoice['total'])->toBe(85500);

    $this->patchJson("/api/v1/invoice-lines/{$invoice['lines'][0]['id']}", ['quantity' => 2])->assertJsonPath('data.subtotal', 40000);
});

it('enregistre les paiements, calcule le reste et passe à « Payée »', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote(['tax_mode' => 'none']);
    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = newInvoice($quote, ['scope' => 'all']);

    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 1000, 'method' => 'cash'])
        ->assertStatus(422)->assertJsonPath('message', "Émettez la facture avant d'enregistrer un paiement.");
    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue")->assertOk();

    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 200000, 'method' => 'bank_transfer', 'reference' => 'VIR 1234'])
        ->assertJsonPath('data.status.label', 'Payée en partie')
        ->assertJsonPath('data.remaining_amount', 340000)
        ->assertJsonPath('data.payments.0.method.label', 'Virement');
    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 400000, 'method' => 'cash'])
        ->assertJsonPath('errors.amount.0', 'Le paiement dépasse le reste à payer (340000).');
    $paid = $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 340000, 'method' => 'orange_money'])
        ->assertJsonPath('data.status.value', 'paid')->json('data');

    // Retirer un paiement rouvre la facture
    $this->deleteJson("/api/v1/invoice-payments/{$paid['payments'][1]['id']}")->assertJsonPath('data.status.value', 'partially_paid');
});

it('annule une facture émise sans paiement', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = newInvoice($quote, ['scope' => 'labor']);
    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue");

    $this->postJson("/api/v1/invoices/{$invoice['id']}/cancel")->assertOk()->assertJsonPath('data.status.label', 'Annulée');
    // Une facture annulée ne compte plus dans le reliquat
    expect(newInvoice($quote, ['scope' => 'remainder'])['subtotal'])->toBe(540000);
});

it('donne les montants à encaisser et en retard', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote(['tax_mode' => 'none']);
    Sanctum::actingAs(userWithRole('accountant'));
    $late = newInvoice($quote, ['scope' => 'labor']);
    $this->postJson("/api/v1/invoices/{$late['id']}/issue", ['issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(10)->toDateString()]);
    $current = newInvoice($quote, ['scope' => 'equipment']);
    $this->postJson("/api/v1/invoices/{$current['id']}/issue");
    $this->postJson("/api/v1/invoices/{$current['id']}/payments", ['amount' => 20000, 'method' => 'cheque']);

    $this->getJson('/api/v1/invoices/summary')->assertJson(['data' => [
        'to_collect' => 100000 + 420000 - 20000, 'overdue_amount' => 100000, 'overdue_count' => 1, 'collected_this_month' => 20000,
    ]]);
    $this->getJson('/api/v1/invoices?overdue=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_overdue', true);
});

it('réserve l\'émission et l\'annulation à invoices.issue ; le commercial consulte seulement', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote->id])->assertForbidden();
    $this->getJson('/api/v1/invoices')->assertOk();

    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = newInvoice($quote, ['scope' => 'all']);

    Sanctum::actingAs(userWithRole('sales'));
    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue")->assertForbidden();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/invoices')->assertForbidden();
    $this->getJson("/api/v1/invoices/{$invoice['id']}/pdf")->assertForbidden();
});

it('produit le PDF, brouillon compris', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = newInvoice($quote, ['scope' => 'all']);

    expect(substr($this->get("/api/v1/invoices/{$invoice['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
    $this->postJson("/api/v1/invoices/{$invoice['id']}/issue");
    $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 100000, 'method' => 'wave']);
    expect(substr($this->get("/api/v1/invoices/{$invoice['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('envoie une facture émise par e-mail avec son PDF et la garde dans l\'historique', function () {
    Mail::fake();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = acceptedQuote();
    Sanctum::actingAs(userWithRole('accountant'));
    $invoice = newInvoice($quote, ['scope' => 'all']);

    $this->getJson("/api/v1/invoices/{$invoice['id']}/email-draft")->assertStatus(422);
    $number = $this->postJson("/api/v1/invoices/{$invoice['id']}/issue")->json('data.number');

    $this->getJson("/api/v1/invoices/{$invoice['id']}/email-draft")->assertJsonPath('data.subject', "Facture {$number} — Facture");
    $this->postJson("/api/v1/invoices/{$invoice['id']}/send", ['to' => ['compta@teranga.sn'], 'subject' => 'Facture', 'message' => 'Bonjour'])
        ->assertOk()->assertJsonPath('data.emails.0.attachments', fn ($files) => count($files) === 1 && str_ends_with($files[0], " - Facture - {$number}.pdf"));

    Mail::assertSent(QuoteMail::class, fn (QuoteMail $m) => $m->hasTo('compta@teranga.sn') && $m->document instanceof Invoice);
});
