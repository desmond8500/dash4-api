<?php

namespace App\Support;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Badge;
use App\Models\BadgeTemplate;
use App\Models\Contract;
use App\Models\Journal;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ReceptionReport;
use App\Models\Room;
use App\Models\Sheet;
use App\Models\Purchase;
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

    public static function purchaseOrder(Purchase $purchase): DomPdf
    {
        $purchase->load(['provider', 'project', 'lines']);
        $company = Company::current();
        $signatureOn = $company->signatureSettings()['purchase_order'] ?? true;

        return Pdf::loadView('pdf.purchase_order', [
            ...self::common($company),
            'purchase' => $purchase,
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');
    }

    /** Rapport d'intervention : sections, intervenants et photos (12 au plus). */
    public static function journal(Journal $journal): DomPdf
    {
        $journal->load(['project', 'client', 'task', 'sections.documents', 'participants.user', 'participants.contact', 'creator', 'documents']);
        // Au plus 30 photos en tout : d'abord celles des sections, puis les photos générales
        $budget = 30;
        $images = function ($documents, int $max) use (&$budget) {
            $list = $documents
                ->filter(fn ($d) => in_array($d->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true))
                ->take(min($max, max(0, $budget)))
                ->map(fn ($d) => ['name' => $d->name, 'src' => self::dataUri(Document::DISK, $d->path)])
                ->filter(fn ($p) => $p['src'] !== null)
                ->values();
            $budget -= $list->count();

            return $list;
        };
        $sectionPhotos = $journal->sections->mapWithKeys(fn ($s) => [$s->id => $images($s->documents, 9)]);
        $photos = $images($journal->documents, 12);

        return Pdf::loadView('pdf.journal', [
            ...self::common(Company::current()),
            'journal' => $journal,
            'photos' => $photos,
            'sectionPhotos' => $sectionPhotos,
        ])->setPaper('a4');
    }

    /** Procès-verbal de réception : parties et logos, systèmes réceptionnés, réserves, signatures. */
    public static function receptionReport(ReceptionReport $report): DomPdf
    {
        $report->load(['project.client', 'quote', 'systems.equipment', 'systems.building']);
        $company = Company::current();
        $signatureOn = $company->signatureSettings()['acceptance'] ?? true;
        $logos = collect(ReceptionReport::PARTIES)->mapWithKeys(fn ($label, $key) => [$key => self::dataUri('public', $report->{"{$key}_logo_path"})]);

        return Pdf::loadView('pdf.reception_report', [
            ...self::common($company),
            'report' => $report,
            'logos' => $logos,
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');
    }

    /** Fiche technique : en-tête, contact et tableau des zones. */
    public static function sheet(Sheet $sheet): DomPdf
    {
        $sheet->load(['project.client', 'building', 'installedSystem', 'zones']);
        $rooms = Room::query()->whereIn('id', $sheet->zones->pluck('room_id')->filter())->with('level')->get()->keyBy('id');

        return Pdf::loadView('pdf.sheet', [...self::common(Company::current()), 'sheet' => $sheet, 'rooms' => $rooms])->setPaper('a4');
    }

    /**
     * Dossier des ouvrages exécutés (DOE) : bâtiments, systèmes et équipements (caractéristiques,
     * emplacement, n° de série), plans (images jointes, PDF listés), fiches, PV et documents.
     */
    public static function doe(Project $project): DomPdf
    {
        $project->load([
            'client', 'manager', 'buildings.levels.rooms',
            'installedSystems.building', 'installedSystems.equipment.article', 'installedSystems.documents',
            'installedSystems.equipment.documents', 'plans.building', 'plans.level', 'plans.markers',
            'sheets.zones', 'receptionReports',
        ]);
        $places = [];
        foreach ($project->buildings as $b) {
            foreach ($b->levels as $l) {
                $places["l{$l->id}"] = "{$b->name} › {$l->name}";
                foreach ($l->rooms as $r) {
                    $places["r{$r->id}"] = "{$b->name} › {$l->name} › {$r->name}";
                }
            }
        }
        // Plans en image joints au dossier (5 Mo au plus chacun, 12 plans au plus)
        $planImages = $project->plans->where('kind', 'image')->filter(fn ($p) => $p->size <= 5 * 1024 * 1024)->take(12)
            ->mapWithKeys(fn ($p) => [$p->id => self::dataUri(Plan::DISK, $p->path)])->filter();

        return Pdf::loadView('pdf.doe', [
            ...self::common(Company::current()),
            'project' => $project,
            'places' => $places,
            'planImages' => $planImages,
        ])->setPaper('a4');
    }

    /**
     * Badges au format carte CR80 (85,6 × 54 mm) : `cards` une carte par page (imprimante à cartes),
     * `sheet` planche A4 de 10 cartes à découper.
     *
     * @param  \Illuminate\Support\Collection<int, Badge>  $badges
     */
    public static function badges($badges, string $layout = 'cards'): DomPdf
    {
        $company = Company::current();
        $cards = $badges->map(function (Badge $b) use ($company) {
            $t = $b->template;
            $portrait = $t?->orientation === 'portrait';

            return [
                'badge' => $b,
                'portrait' => $portrait,
                'primary' => $t?->primary_color ?? '#1d4ed8',
                'text' => $t?->text_color ?? '#111827',
                'title' => $t?->title ?? $company->name,
                'subtitle' => $t?->subtitle,
                'footer' => $t?->footer,
                'fields' => $t ? $t->printedFields() : ['job_title', 'department'],
                'logo' => $t?->logo_path ? self::dataUri('public', $t->logo_path) : self::dataUri('public', $company->logo_path),
                'background' => $t?->background_path ? self::dataUri('public', $t->background_path) : null,
                'photo' => $b->photo_path ? self::dataUri('public', $b->photo_path) : null,
            ];
        });
        $pdf = Pdf::loadView('pdf.badges', ['cards' => $cards, 'layout' => $layout, 'labels' => BadgeTemplate::FIELDS]);
        if ($layout === 'sheet') {
            return $pdf->setPaper('a4');
        }
        // 85,6 × 54 mm en points ; une carte en portrait si le premier modèle l'est
        $portrait = (bool) ($cards->first()['portrait'] ?? false);

        return $pdf->setPaper($portrait ? [0, 0, 153.07, 242.65] : [0, 0, 242.65, 153.07]);
    }

    /** Contrat de maintenance : parties, durée, facturation, prestations et conditions. */
    public static function contract(Contract $contract): DomPdf
    {
        $contract->load(['client', 'project', 'lines']);
        $company = Company::current();

        $signatureOn = $company->signatureSettings()['contract'] ?? true;

        return Pdf::loadView('pdf.contract', [
            ...self::common($company),
            'contract' => $contract,
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
