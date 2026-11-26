<?php

namespace App\Support;

use App\Enums\TaxMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres généraux de Dash4 : valeurs par défaut ci-dessous, remplacées par celles
 * enregistrées dans la table `settings` (modifiables par settings.update).
 */
final class Settings
{
    public const DEFAULTS = [
        'currency' => 'XOF',
        'vat_rate' => 18,
        'brs_rate' => 5,
        // Devis et factures sans taxe par défaut (décision du 18/11) ; TVA ou BRS se choisissent sur le document
        'default_tax' => 'none',
        // {YYYY} année, {YY} année sur 2 chiffres, {MM} mois, {####} numéro (autant de chiffres que de #)
        // Relances de paiement : paliers en jours après l'échéance ; envoi automatique au client désactivé par défaut
        'reminders' => ['auto' => false, 'days' => [7, 15, 30]],
        // Articles achetés à l'étranger : taux du dollar (l'euro est à parité fixe), taxe en % et frais de port en F CFA
        'import_costs' => ['usd_rate' => 600, 'tax_rate' => 20, 'shipping' => 10000],
        'numbering' => [
            'quote' => 'DEV-{YYYY}-{####}',
            'invoice' => 'FAC-{YYYY}-{####}',
            'credit_note' => 'AV-{YYYY}-{####}',
            'delivery_note' => 'BL-{YYYY}-{####}',
            'purchase_order' => 'BC-{YYYY}-{####}',
            'contract' => 'CTR-{YYYY}-{####}',
        ],
    ];

    public const DOCUMENT_TYPES = [
        'quote' => 'Devis',
        'invoice' => 'Factures',
        'credit_note' => 'Avoirs',
        'delivery_note' => 'Bons de livraison',
        'purchase_order' => 'Bons de commande',
        'contract' => 'Contrats',
    ];

    private const CACHE_KEY = 'dash4.settings';

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true))
            ->all());

        $settings = array_replace(self::DEFAULTS, $stored);
        // Formats : on complète ceux qui manquent avec les valeurs par défaut
        $settings['numbering'] = array_replace(self::DEFAULTS['numbering'], $stored['numbering'] ?? []);
        $settings['reminders'] = array_replace(self::DEFAULTS['reminders'], $stored['reminders'] ?? []);
        $settings['import_costs'] = array_replace(self::DEFAULTS['import_costs'], $stored['import_costs'] ?? []);

        return $settings;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
        Cache::forget(self::CACHE_KEY);
    }

    public static function defaultTax(): TaxMode
    {
        return TaxMode::tryFrom((string) self::get('default_tax')) ?? TaxMode::None;
    }
}
