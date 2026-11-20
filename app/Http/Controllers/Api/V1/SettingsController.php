<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaxMode;
use App\Http\Controllers\Controller;
use App\Support\DocumentNumber;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Paramètres généraux : devise, TVA, BRS, taxe par défaut, numérotation.
 * Lecture pour tout compte connecté (nécessaire aux devis et factures), modification settings.update.
 */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.update'), 403, "Vous n'avez pas le droit de faire cette action.");

        $data = $request->validate([
            'currency' => ['sometimes', 'string', 'in:XOF,EUR,USD'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'brs_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'default_tax' => ['sometimes', new Enum(TaxMode::class)],
            'reminders' => ['sometimes', 'array:auto,days'],
            'reminders.auto' => ['sometimes', 'boolean'],
            'reminders.days' => ['sometimes', 'array', 'min:1', 'max:5'],
            'reminders.days.*' => ['integer', 'between:1,365', 'distinct'],
            'numbering' => ['sometimes', 'array:'.implode(',', array_keys(Settings::DOCUMENT_TYPES))],
            // Un numéro {####} obligatoire ; lettres, chiffres, tirets, barres, points et {YYYY} {YY} {MM}
            'numbering.*' => ['string', 'max:40', 'regex:/\{#{1,8}\}/', 'regex:/^([A-Za-z0-9\-\/_. ]|\{YYYY\}|\{YY\}|\{MM\}|\{#{1,8}\})+$/'],
        ], [
            'numbering.*.regex' => 'Le format doit contenir un numéro, par exemple {####}, et seulement des lettres, chiffres, - / _ . et {YYYY} {YY} {MM}.',
        ], [
            'currency' => 'devise', 'vat_rate' => 'taux de TVA', 'brs_rate' => 'taux de BRS', 'default_tax' => 'taxe par défaut',
        ]);

        if (isset($data['reminders'])) {
            $data['reminders'] = array_replace(Settings::get('reminders'), $data['reminders']);
            $days = array_map('intval', $data['reminders']['days']);
            sort($days);
            $data['reminders']['days'] = array_values($days);
            $data['reminders']['auto'] = (bool) $data['reminders']['auto'];
        }
        if (isset($data['numbering'])) {
            $data['numbering'] = array_replace(Settings::get('numbering'), $data['numbering']);
        }
        foreach (['vat_rate', 'brs_rate'] as $rate) {
            if (isset($data[$rate])) {
                $data[$rate] = (float) $data[$rate];
            }
        }

        Settings::set($data);

        return response()->json(['data' => $this->payload()]);
    }

    /** GET /api/v1/settings/numbering-preview?type=quote&format=DEV-{YY}-{###} */
    public function numberingPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(Settings::DOCUMENT_TYPES))],
            'format' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(['data' => ['next' => DocumentNumber::preview($data['type'], $data['format'] ?? null)]]);
    }

    private function payload(): array
    {
        $settings = Settings::all();

        return [
            ...$settings,
            'next_numbers' => collect(Settings::DOCUMENT_TYPES)->keys()
                ->mapWithKeys(fn ($type) => [$type => DocumentNumber::preview($type)])->all(),
        ];
    }
}
