<?php

namespace App\Support;

/**
 * Coût d'achat en F CFA d'un article acheté à l'étranger :
 * prix en devise × taux, plus la taxe (20 % par défaut), plus les frais de port (10 000 F par défaut), arrondi au franc.
 * L'euro a une parité fixe avec le franc CFA ; le taux du dollar, la taxe et les frais se règlent dans les Paramètres.
 */
final class ImportCost
{
    /** Parité fixe : 1 € = 655,957 F CFA. */
    public const EUR_RATE = 655.957;

    public const CURRENCIES = ['EUR', 'USD'];

    /** @return array{rate: float, tax_rate: float, shipping: float} */
    public static function parameters(string $currency): array
    {
        $settings = Settings::get('import_costs');

        return [
            'rate' => $currency === 'EUR' ? self::EUR_RATE : (float) $settings['usd_rate'],
            'tax_rate' => (float) $settings['tax_rate'],
            'shipping' => (float) $settings['shipping'],
        ];
    }

    public static function cfa(float $price, string $currency): int
    {
        $p = self::parameters($currency);

        return (int) round($price * $p['rate'] * (1 + $p['tax_rate'] / 100) + $p['shipping']);
    }
}
