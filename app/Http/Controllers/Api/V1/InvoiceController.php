<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceScope;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\QuoteStatus;
use App\Enums\TaxMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\Quote;
use App\Models\QuoteDeposit;
use App\Support\DocumentMailer;
use App\Support\DocumentNumber;
use App\Support\InvoiceBuilder;
use App\Support\QuotePdf;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/**
 * Factures et paiements. Brouillon modifiable sans numéro ; à l'émission (invoices.issue),
 * numéro FAC-2026-0001 et facture verrouillée ; paiements sur une facture émise.
 */
class InvoiceController extends Controller
{
    public const FULL = [
        'client:id,name', 'project:id,name', 'quote:id,number,title,total', 'bankAccount', 'lines',
        'payments.creator:id,firstname,lastname', 'emails.sender:id,firstname,lastname',
        'creator:id,firstname,lastname', 'issuer:id,firstname,lastname',
        'creditNotes.creator:id,firstname,lastname', 'reminders.sender:id,firstname,lastname',
    ];

    /**
     * GET /api/v1/invoices — filtres : status (liste), unpaid=1 (émises non payées), overdue=1,
     * client_id, quote_id, project_id, year, search ; synchro : updated_since.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()
            ->visibleTo($request->user())
            ->with(['client:id,name', 'project:id,name', 'quote:id,number,title,total'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('unpaid'), fn ($q) => $q->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])->whereDate('due_at', '<', now()))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('quote_id'), fn ($q) => $q->where('quote_id', $request->integer('quote_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('issued_at', $request->integer('year')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhere('title', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->orderByRaw("status = 'draft' desc")
            ->latest('issued_at')
            ->orderByDesc('id');

        SyncQuery::apply($query, $request);

        return InvoiceResource::collection($query->paginate(SyncQuery::perPage($request)))->additional(SyncQuery::meta());
    }

    /** GET /api/v1/invoices/summary — à encaisser, en retard, encaissé ce mois-ci, brouillons. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);
        $open = fn () => Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]);

        return response()->json(['data' => [
            'to_collect' => (int) $open()->sum(DB::raw('total - paid_amount')),
            'unpaid_count' => $open()->count(),
            'overdue_amount' => (int) $open()->whereDate('due_at', '<', now())->sum(DB::raw('total - paid_amount')),
            'overdue_count' => $open()->whereDate('due_at', '<', now())->count(),
            'collected_this_month' => (int) InvoicePayment::query()->whereBetween('paid_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'),
            'drafts' => Invoice::query()->where('status', InvoiceStatus::Draft->value)->count(),
        ]]);
    }

    /**
     * POST /api/v1/invoices
     *  - depuis un devis accepté : `quote_id`, `scope` (all, equipment, equipment_accessories, labor, remainder), `percent`, `title` ;
     *  - depuis un acompte prévu sur le devis : `quote_deposit_id` ;
     *  - facture libre : `client_id`, `title`.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')->whereNull('deleted_at')],
            'quote_deposit_id' => ['nullable', 'integer', Rule::exists('quote_deposits', 'id')->whereNull('deleted_at')],
            'scope' => ['sometimes', new Enum(InvoiceScope::class)],
            'percent' => ['sometimes', 'numeric', 'min:0.01', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required_without_all:quote_id,quote_deposit_id', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
        ], ['client_id.required_without_all' => 'Choisissez le devis ou le client à facturer.'], [
            'quote_id' => 'devis', 'scope' => 'partie facturée', 'percent' => 'pourcentage', 'title' => 'titre', 'client_id' => 'client',
        ]);

        if ($request->filled('client_uuid') && $existing = Invoice::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new InvoiceResource($existing->load(self::FULL)))->response()->setStatusCode(200);
        }

        $user = $request->user();
        if (! empty($data['quote_deposit_id'])) {
            $deposit = QuoteDeposit::findOrFail($data['quote_deposit_id']);
            $this->ensureInvoiceable($deposit->quote);
            abort_if(Invoice::where('quote_deposit_id', $deposit->id)->where('status', '!=', 'cancelled')->exists(), 422, 'Cet acompte a déjà sa facture.');
            $invoice = InvoiceBuilder::fromDeposit($deposit, $user);
        } elseif (! empty($data['quote_id'])) {
            $quote = Quote::findOrFail($data['quote_id']);
            $this->ensureInvoiceable($quote);
            $scope = InvoiceScope::from($data['scope'] ?? 'all');
            abort_if($scope === InvoiceScope::Custom, 422, 'Une facture libre ne part pas d\'un devis.');
            $invoice = InvoiceBuilder::fromQuote($quote, $scope, (float) ($data['percent'] ?? 100), $data['title'] ?? null, $user);
        } else {
            $invoice = InvoiceBuilder::blank(['client_id' => $data['client_id'], 'title' => $data['title'] ?? 'Facture'], $user);
        }

        if (! empty($data['client_uuid'])) {
            $invoice->forceFill(['client_uuid' => $data['client_uuid']])->saveQuietly();
        }

        return (new InvoiceResource($invoice->refresh()->load(self::FULL)))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return $this->full($invoice);
    }

    /** PATCH /api/v1/invoices/{id} — en-tête d'un brouillon ; notes internes toujours modifiables. */
    public function update(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'introduction' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'tax_mode' => ['sometimes', new Enum(TaxMode::class)],
            'bank_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],
            'show_signature' => ['sometimes', 'boolean'],
        ], [], ['title' => 'titre', 'due_at' => 'échéance', 'tax_mode' => 'taxe', 'bank_account_id' => 'compte bancaire']);

        if (array_diff(array_keys($data), ['notes']) !== []) {
            $this->ensureDraft($invoice);
        }
        $invoice->update($data);
        if ($invoice->wasChanged('tax_mode')) {
            $invoice->recalculate();
        }

        return $this->full($invoice);
    }

    /** POST /api/v1/invoices/{id}/lines — ligne libre (prix négatif possible : remise, déduction). */
    public function addLine(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);
        $this->ensureDraft($invoice);
        $invoice->lines()->create([...$this->validateLine($request, true), 'position' => (int) $invoice->lines()->max('position') + 1]);
        $invoice->recalculate();

        return $this->full($invoice);
    }

    public function updateLine(Request $request, InvoiceLine $line): InvoiceResource
    {
        $invoice = Invoice::findOrFail($line->invoice_id);
        $this->authorize('update', $invoice);
        $this->ensureDraft($invoice);
        $line->update($this->validateLine($request, false));
        $invoice->recalculate();

        return $this->full($invoice);
    }

    public function removeLine(InvoiceLine $line): InvoiceResource
    {
        $invoice = Invoice::findOrFail($line->invoice_id);
        $this->authorize('update', $invoice);
        $this->ensureDraft($invoice);
        $line->delete();
        $invoice->recalculate();

        return $this->full($invoice);
    }

    /**
     * POST /api/v1/invoices/{id}/issue — `issued_at` (aujourd'hui par défaut), `due_at` (30 jours par défaut).
     * Numéro attribué dans l'ordre, puis la facture ne se modifie plus.
     */
    public function issue(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('issue', $invoice);
        $this->ensureDraft($invoice);
        $data = $request->validate([
            'issued_at' => ['sometimes', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date', ...($request->filled('issued_at') ? ['after_or_equal:issued_at'] : [])],
        ], [], ['issued_at' => "date d'émission", 'due_at' => 'échéance']);
        abort_if($invoice->lines()->doesntExist(), 422, 'Une facture sans ligne ne peut pas être émise.');
        abort_if($invoice->total <= 0, 422, 'Le total de la facture doit être positif.');

        DB::transaction(function () use ($invoice, $data, $request) {
            $issued = isset($data['issued_at']) ? now()->parse($data['issued_at']) : now();
            $invoice->forceFill([
                'number' => DocumentNumber::next('invoice', $issued),
                'status' => InvoiceStatus::Issued,
                'issued_at' => $issued->toDateString(),
                'due_at' => $data['due_at'] ?? $invoice->due_at ?? $issued->copy()->addDays(30)->toDateString(),
                'issued_by' => $request->user()->id,
            ])->save();
            $invoice->refreshPayments();
        });

        return $this->full($invoice);
    }

    /** POST /api/v1/invoices/{id}/cancel — facture émise annulée (l'avoir viendra en V2) ; sans paiement. */
    public function cancel(Invoice $invoice): InvoiceResource
    {
        $this->authorize('issue', $invoice);
        abort_if($invoice->isDraft(), 422, 'Un brouillon se supprime, il ne s\'annule pas.');
        abort_if($invoice->payments()->exists(), 422, 'Cette facture a des paiements : retirez-les avant de l\'annuler.');
        $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

        return $this->full($invoice);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);
        $invoice->delete();

        return response()->json(['message' => 'Brouillon supprimé.']);
    }

    /** POST /api/v1/invoices/{id}/payments — sur une facture émise, sans dépasser le reste à payer. */
    public function addPayment(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);
        abort_unless(in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true), 422,
            $invoice->isDraft() ? 'Émettez la facture avant d\'enregistrer un paiement.' : "Cette facture est « {$invoice->status->label()} ».");
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.$invoice->remainingAmount()],
            'paid_at' => ['sometimes', 'date'],
            'method' => ['required', new Enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['amount.max' => 'Le paiement dépasse le reste à payer (:max).'], [
            'amount' => 'montant', 'paid_at' => 'date', 'method' => 'mode de paiement', 'reference' => 'référence',
        ]);

        $payment = new InvoicePayment([...$data, 'paid_at' => $data['paid_at'] ?? now()->toDateString()]);
        $payment->created_by = $request->user()->id;
        $invoice->payments()->save($payment);
        $invoice->refreshPayments();

        return $this->full($invoice);
    }

    public function removePayment(InvoicePayment $payment): InvoiceResource
    {
        $invoice = Invoice::findOrFail($payment->invoice_id);
        $this->authorize('update', $invoice);
        $payment->delete();
        $invoice->refreshPayments();

        return $this->full($invoice);
    }

    /** GET /api/v1/invoices/{id}/pdf — un brouillon porte la mention « Brouillon ». */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);
        $pdf = QuotePdf::invoice($invoice);
        $filename = PdfName::invoice($invoice->loadMissing('client'));

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    public function emailDraft(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        abort_if($invoice->isDraft(), 422, 'Émettez la facture avant de l\'envoyer.');
        $remaining = number_format($invoice->remainingAmount(), 0, ',', ' ');

        return response()->json(['data' => DocumentMailer::draft(
            $invoice->client,
            $request->user(),
            "Facture {$invoice->number} — {$invoice->title}",
            "Bonjour,\n\nVeuillez trouver ci-joint notre facture {$invoice->number} ({$invoice->title}).\n"
                ."Montant restant à régler : {$remaining} F CFA"
                .($invoice->due_at ? ", avant le {$invoice->due_at->format('d/m/Y')}" : '').".\n\n"
                .'Nous restons à votre disposition pour toute question.',
        )]);
    }

    public function send(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);
        abort_if($invoice->isDraft(), 422, 'Émettez la facture avant de l\'envoyer.');
        $data = DocumentMailer::validate($request);
        $files = [['name' => PdfName::invoice($invoice->loadMissing('client')), 'data' => QuotePdf::invoice($invoice)->output()]];

        DocumentMailer::send($invoice, $data, $files, $request->user(), "Facture {$invoice->number}");

        return $this->full($invoice);
    }

    /** GET /api/v1/invoices/{invoice}/reminder-draft — relance suivante proposée (objet, texte, destinataires). */
    public function reminderDraft(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);
        abort_unless(in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true), 422, 'Seule une facture émise et non soldée se relance.');

        return response()->json(['data' => \App\Support\InvoiceReminders::draft($invoice, $request->user())]);
    }

    /** POST /api/v1/invoices/{invoice}/remind — envoie la relance au client (PDF joint). */
    public function remind(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);
        abort_unless(in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true), 422, 'Seule une facture émise et non soldée se relance.');
        \App\Support\InvoiceReminders::send($invoice, DocumentMailer::validate($request), $request->user());

        return $this->full($invoice);
    }

    /**
     * GET /api/v1/invoices/schedule — échéancier des encaissements attendus : factures émises non soldées,
     * acomptes prévus non payés (devis acceptés), prochaines périodes des contrats actifs. `months` (6 par défaut).
     */
    public function schedule(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('invoices.view') && $request->user()->can('prices.view'), 403, "Vous n'avez pas le droit de faire cette action.");
        $until = now()->addMonths(min(24, max(1, $request->integer('months', 6))))->endOfMonth();
        $items = collect();

        Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->with('client:id,name')->get()
            ->each(function (Invoice $i) use ($items) {
                $items->push([
                    'kind' => 'invoice', 'id' => $i->id, 'label' => "{$i->number} — {$i->title}",
                    'client' => $i->client?->name, 'date' => ($i->due_at ?? $i->issued_at)?->toDateString(),
                    'amount' => $i->remainingAmount(), 'overdue' => $i->isOverdue(),
                ]);
            });

        \App\Models\QuoteDeposit::query()->where('is_paid', false)->whereNotNull('due_date')->whereDate('due_date', '<=', $until)
            ->whereHas('quote', fn ($q) => $q->where('status', QuoteStatus::Accepted->value))
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled->value))
            ->with('quote.client:id,name')->get()
            ->each(function ($d) use ($items) {
                $items->push([
                    'kind' => 'deposit', 'id' => $d->quote_id, 'label' => "Acompte « {$d->label} » — devis {$d->quote->number}",
                    'client' => $d->quote->client?->name, 'date' => $d->due_date->toDateString(),
                    'amount' => (int) $d->amount, 'overdue' => $d->due_date->isPast() && ! $d->due_date->isToday(),
                ]);
            });

        \App\Models\Contract::query()->where('status', 'active')->whereNotNull('next_invoice_date')->whereDate('next_invoice_date', '<=', $until)
            ->with(['client:id,name', 'lines'])->get()
            ->each(function ($c) use ($items) {
                $items->push([
                    'kind' => 'contract', 'id' => $c->id, 'label' => "Contrat {$c->number} — {$c->title}",
                    'client' => $c->client?->name, 'date' => $c->next_invoice_date->toDateString(),
                    'amount' => (int) round($c->periodAmount()), 'overdue' => false,
                ]);
            });

        $items = $items->sortBy(fn ($i) => $i['date'] ?? '9999')->values();

        return response()->json(['data' => $items, 'meta' => [
            'total' => $items->sum('amount'),
            'overdue' => $items->where('overdue', true)->sum('amount'),
        ]]);
    }

    private function ensureInvoiceable(Quote $quote): void
    {
        abort_unless(
            $quote->status === QuoteStatus::Accepted,
            422,
            "Seul un devis accepté se facture (ce devis est « {$quote->status->label()} »).",
        );
    }

    private function ensureDraft(Invoice $invoice): void
    {
        abort_unless($invoice->isDraft(), 422, "Cette facture est « {$invoice->status->label()} » : elle ne se modifie plus.");
    }

    private function validateLine(Request $request, bool $creating): array
    {
        return $request->validate([
            'designation' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'quantity' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'unit_price' => [$creating ? 'required' : 'sometimes', 'integer', 'min:-999999999999', 'max:999999999999'],
        ], [], ['designation' => 'désignation', 'quantity' => 'quantité', 'unit_price' => 'prix unitaire']);
    }

    private function full(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->refresh()->load(self::FULL));
    }
}
