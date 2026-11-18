<?php

namespace App\Support;

use App\Enums\TaxMode;

/**
 * Totaux d'un document à partir du total hors taxes (règle de Dash3) :
 *  - TVA : total = HT + TVA ;
 *  - BRS : total = HT − retenue BRS ;
 *  - sans taxe : total = HT.
 * Montants en francs CFA, arrondis à l'unité.
 */
final class TaxCalculator
{
    /** @return array{subtotal: int, tax_mode: string, rate: float, tax: int, total: int} */
    public static function totals(int|float $subtotal, TaxMode $mode, ?float $vatRate = null, ?float $brsRate = null): array
    {
        $subtotal = (int) round($subtotal);
        $rate = match ($mode) {
            TaxMode::Vat => $vatRate ?? (float) Settings::get('vat_rate'),
            TaxMode::Brs => $brsRate ?? (float) Settings::get('brs_rate'),
            TaxMode::None => 0.0,
        };
        $tax = (int) round($subtotal * $rate / 100);

        return [
            'subtotal' => $subtotal,
            'tax_mode' => $mode->value,
            'rate' => $rate,
            'tax' => $tax,
            'total' => $mode === TaxMode::Brs ? $subtotal - $tax : $subtotal + $tax,
        ];
    }
}
