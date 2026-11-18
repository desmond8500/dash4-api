<?php

use App\Enums\TaxMode;
use App\Support\DocumentNumber;
use App\Support\Settings;
use App\Support\TaxCalculator;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

it('donne les paramètres par défaut : franc CFA, TVA 18 %, BRS 5 %, sans taxe par défaut', function () {
    Sanctum::actingAs(userWithRole('technician'));

    $this->getJson('/api/v1/settings')->assertOk()->assertJson(['data' => [
        'currency' => 'XOF', 'vat_rate' => 18, 'brs_rate' => 5, 'default_tax' => 'none',
        'numbering' => ['quote' => 'DEV-{YYYY}-{####}', 'invoice' => 'FAC-{YYYY}-{####}'],
        'next_numbers' => ['quote' => 'DEV-'.now()->year.'-0001'],
    ]]);
});

it('laisse l\'administrateur changer les taux, la taxe par défaut et la numérotation', function () {
    Sanctum::actingAs(userWithRole('admin'));

    $this->patchJson('/api/v1/settings', [
        'vat_rate' => 18, 'brs_rate' => 5, 'default_tax' => 'brs', 'numbering' => ['quote' => 'D{YY}/{###}'],
    ])->assertOk()
        ->assertJsonPath('data.default_tax', 'brs')
        ->assertJsonPath('data.numbering.quote', 'D{YY}/{###}')
        ->assertJsonPath('data.numbering.invoice', 'FAC-{YYYY}-{####}');

    expect(Settings::defaultTax())->toBe(TaxMode::Brs);
});

it('refuse un format de numéro sans numéro ou avec des caractères interdits', function () {
    Sanctum::actingAs(userWithRole('admin'));

    foreach (['DEV-{YYYY}', 'DEV-<script>{###}', 'DEV-{XX}-{###}'] as $format) {
        $this->patchJson('/api/v1/settings', ['numbering' => ['quote' => $format]])
            ->assertJsonValidationErrors('numbering.quote');
    }
    $this->patchJson('/api/v1/settings', ['default_tax' => 'tva'])->assertJsonValidationErrors('default_tax');
});

it('réserve la modification des paramètres à settings.update', function () {
    Sanctum::actingAs(userWithRole('accountant'));

    $this->patchJson('/api/v1/settings', ['vat_rate' => 20])->assertForbidden();
});

it('numérote sans trou, et repart à 1 chaque année', function () {
    $d2026 = Carbon::create(2026, 11, 18);

    expect(DocumentNumber::next('quote', $d2026))->toBe('DEV-2026-0001')
        ->and(DocumentNumber::next('quote', $d2026))->toBe('DEV-2026-0002')
        ->and(DocumentNumber::next('invoice', $d2026))->toBe('FAC-2026-0001')
        ->and(DocumentNumber::next('quote', Carbon::create(2027, 1, 2)))->toBe('DEV-2027-0001')
        ->and(DocumentNumber::preview('quote', null, $d2026))->toBe('DEV-2026-0003');
});

it('applique les formats personnalisés', function () {
    $date = Carbon::create(2026, 3, 5);

    expect(DocumentNumber::format('D{YY}{MM}-{###}', 7, $date))->toBe('D2603-007')
        ->and(DocumentNumber::format('FAC/{YYYY}/{#}', 12, $date))->toBe('FAC/2026/12');
});

it('calcule les totaux comme Dash3 : TVA ajoutée, BRS retenue', function () {
    expect(TaxCalculator::totals(1_000_000, TaxMode::Vat))->toBe([
        'subtotal' => 1_000_000, 'tax_mode' => 'vat', 'rate' => 18.0, 'tax' => 180_000, 'total' => 1_180_000,
    ]);
    expect(TaxCalculator::totals(1_000_000, TaxMode::Brs)['total'])->toBe(950_000);
    expect(TaxCalculator::totals(1_000_000, TaxMode::None)['total'])->toBe(1_000_000);
    expect(TaxCalculator::totals(333_333, TaxMode::Vat)['tax'])->toBe(60_000);
});
