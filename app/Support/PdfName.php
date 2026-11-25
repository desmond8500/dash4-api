<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\CreditNote;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\ReceptionReport;
use App\Models\Sheet;

/**
 * Noms des fichiers PDF : « Client - Objet - Référence.pdf », sans les morceaux vides.
 * Le type de document passe devant quand plusieurs PDF partagent la même référence
 * (« Proposition technique - … », « Quantitatif - … ») ou quand le document n'a pas de numéro (rapport, PV…).
 * Les caractères interdits dans un nom de fichier sont retirés ; l'objet est raccourci au besoin.
 */
final class PdfName
{
    private const MAX_PART = 80;

    /** @param  array<int, string|null>  $parts */
    public static function make(array $parts, string $extension = 'pdf'): string
    {
        $clean = array_values(array_filter(array_map(fn ($p) => self::clean($p), $parts), fn ($p) => $p !== ''));

        return (implode(' - ', $clean) ?: 'Document').'.'.$extension;
    }

    public static function quote(Quote $quote, ?string $type = null, ?string $suffix = null, string $extension = 'pdf'): string
    {
        return self::make([$type, $quote->client?->name, $quote->title, $quote->number, $suffix], $extension);
    }

    public static function invoice(Invoice $invoice): string
    {
        return self::make([$invoice->client?->name, $invoice->title, $invoice->number ?? 'Brouillon']);
    }

    public static function creditNote(CreditNote $note): string
    {
        $invoice = $note->invoice;

        return self::make([$note->client?->name ?? $invoice?->client?->name, $invoice?->title, $note->number]);
    }

    public static function deliveryNote(DeliveryNote $note): string
    {
        return self::make([$note->quote?->client?->name, $note->title ?: $note->quote?->title, $note->number]);
    }

    public static function contract(Contract $contract): string
    {
        return self::make([$contract->client?->name, $contract->title, $contract->number]);
    }

    /** Rapport d'intervention : pas de numéro, la date le distingue. */
    public static function journal(Journal $journal): string
    {
        $client = $journal->client?->name ?? $journal->project?->client?->name;

        return self::make(['Rapport', $client, $journal->title, $journal->date?->format('Y-m-d')]);
    }

    public static function receptionReport(ReceptionReport $report): string
    {
        return self::make(['PV de réception', $report->project?->client?->name ?? $report->client_name, $report->title, $report->date?->format('Y-m-d')]);
    }

    /** Bon de commande : le fournisseur tient la place du client. */
    public static function purchase(Purchase $purchase): string
    {
        return self::make([$purchase->provider?->name, $purchase->title, $purchase->number]);
    }

    public static function project(Project $project, string $type): string
    {
        return self::make([$type, $project->client?->name, $project->name]);
    }

    public static function sheet(Sheet $sheet): string
    {
        return self::make(['Fiche', $sheet->project?->client?->name, $sheet->title]);
    }

    private static function clean(?string $value): string
    {
        $value = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', (string) $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > self::MAX_PART) {
            $value = rtrim(mb_substr($value, 0, self::MAX_PART - 1)).'…';
        }

        return trim($value, ' .-');
    }
}
