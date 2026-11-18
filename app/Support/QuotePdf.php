<?php

namespace App\Support;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/** PDF du devis, de sa proposition technique et de ses bordereaux. */
final class QuotePdf
{
    public static function quote(Quote $quote): DomPdf
    {
        $quote->load([
            'client.phones', 'client.emails', 'project', 'bankAccount',
            'sections' => fn ($q) => $q->where('is_visible', true), 'sections.lines',
            'deposits' => fn ($q) => $q->where('show_on_pdf', true),
        ]);
        $company = Company::current();
        $signatureOn = $quote->show_signature && ($company->signatureSettings()['quote'] ?? true);

        return Pdf::loadView('pdf.quote', [
            ...self::common($company),
            'quote' => $quote,
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');
    }

    /** Proposition technique : description de chaque section, lignes sans prix (ou avec, au choix). */
    public static function proposal(Quote $quote): DomPdf
    {
        $quote->load(['client', 'project', 'sections' => fn ($q) => $q->where('is_visible', true), 'sections.lines']);
        $company = Company::current();

        return Pdf::loadView('pdf.proposal', [
            ...self::common($company),
            'quote' => $quote,
            'options' => $quote->proposalOptions(),
        ])->setPaper('a4');
    }

    public static function deliveryNote(DeliveryNote $note): DomPdf
    {
        $note->load(['quote.client.phones', 'quote.project', 'lines']);
        $company = Company::current();
        $signatureOn = $company->signatureSettings()['delivery_note'] ?? true;

        return Pdf::loadView('pdf.delivery_note', [
            ...self::common($company),
            'note' => $note,
            'quote' => $note->quote,
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');
    }

    public static function invoice(Invoice $invoice): DomPdf
    {
        $invoice->load(['client.phones', 'client.emails', 'project', 'quote', 'bankAccount', 'lines', 'payments']);
        $company = Company::current();
        $signatureOn = $invoice->show_signature && ($company->signatureSettings()['invoice'] ?? true);

        return Pdf::loadView('pdf.invoice', [
            ...self::common($company),
            'invoice' => $invoice,
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');
    }

    private static function common(Company $company): array
    {
        return [
            'company' => $company,
            'currency' => Settings::get('currency'),
            'logo' => self::dataUri('public', $company->logo_path),
        ];
    }

    /** Image intégrée au PDF (data URI) : rien n'est chargé depuis l'extérieur. */
    public static function dataUri(string $disk, ?string $path): ?string
    {
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }
        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }
}
