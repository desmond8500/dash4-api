<?php

use App\Models\Client;
use App\Models\Invoice;
use Laravel\Sanctum\Sanctum;

function exportInvoice(string $mode, int $subtotal, int $tax, int $total): Invoice
{
    $invoice = Invoice::query()->forceCreate([
        'number' => 'FAC-2026-'.random_int(1000, 9999), 'client_id' => Client::factory()->create()->id, 'title' => 'Alarme',
        'status' => 'issued', 'issued_at' => '2026-11-10', 'tax_mode' => $mode, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total,
    ]);
    $invoice->payments()->forceCreate(['amount' => 50000, 'paid_at' => '2026-11-12', 'method' => 'wave']);

    return $invoice;
}

it('exporte les factures en CSV et un journal FEC équilibré', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $vat = exportInvoice('vat', 100000, 18000, 118000);
    exportInvoice('brs', 100000, 5000, 95000);

    $csv = $this->get('/api/v1/exports/invoices?from=2026-11-01&to=2026-11-30')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain('Numéro;Date')->toContain($vat->number);

    $fec = $this->get('/api/v1/exports/fec?from=2026-11-01&to=2026-11-30')->assertOk()->streamedContent();
    $lines = array_map(fn ($l) => explode("\t", $l), array_filter(explode("\r\n", $fec)));
    expect($lines[0][0])->toBe('JournalCode');
    $body = array_slice($lines, 1);
    $sum = fn ($col) => array_sum(array_map(fn ($l) => (float) str_replace(',', '.', $l[$col]), $body));
    // Chaque pièce est équilibrée : total des débits = total des crédits
    expect(round($sum(11), 2))->toBe(round($sum(12), 2));
    $byPiece = collect($body)->groupBy(2)->map(fn ($p) => round($p->sum(fn ($l) => (float) str_replace(',', '.', $l[11]) - (float) str_replace(',', '.', $l[12])), 2));
    expect($byPiece->filter()->all())->toBe([]);
    expect(collect($body)->pluck(4)->unique()->values()->all())->toContain('443100', '449100', '521100');

    $this->getJson('/api/v1/exports/fec?from=2026-11-30&to=2026-11-01')->assertJsonValidationErrors('to');
});

it('réserve les exports à qui en a le droit', function () {
    foreach (['sales', 'technician', 'project_manager'] as $role) {
        Sanctum::actingAs(userWithRole($role));
        $this->getJson('/api/v1/exports/invoices?from=2026-11-01&to=2026-11-30')->assertForbidden();
    }
});
