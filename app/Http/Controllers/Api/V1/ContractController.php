<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingPeriod;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Contract;
use App\Models\FlatRate;
use App\Models\Invoice;
use App\Support\DocumentNumber;
use App\Support\InvoiceBuilder;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrats de maintenance (`contracts.*`) et forfaits. Chaque période devient une facture brouillon
 * (lignes du contrat, période indiquée) ; la date de la prochaine facture avance d'une période.
 */
class ContractController extends Controller
{
    private const FULL = ['client:id,name', 'project:id,name', 'lines', 'invoices', 'visits.task:id,title,status,due_date'];

    /** GET /api/v1/contracts — filtres : status, client_id, due=1 (à facturer), ending=1 (échéance proche), search. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contract::class);
        $query = Contract::query()->with(['client:id,name', 'project:id,name', 'lines'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->boolean('due'), fn ($q) => $q->where('status', 'active')->whereDate('next_invoice_date', '<=', today()))
            ->when($request->boolean('ending'), fn ($q) => $q->whereKey(self::endingIds()))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->string('search').'%')->orWhere('number', 'like', '%'.$request->string('search').'%')))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")->latest('start_date');

        return ContractResource::collection($query->paginate(min($request->integer('per_page', 50), 200)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);
        $data = $this->validated($request, true);
        $contract = DB::transaction(function () use ($data, $request) {
            $contract = new Contract(array_diff_key($data, ['lines' => true]));
            $contract->next_invoice_date ??= $contract->start_date;
            $contract->number = DocumentNumber::next('contract', Carbon::parse($contract->start_date));
            $contract->created_by = $request->user()->id;
            $contract->save();
            $this->writeLines($contract, $data['lines'] ?? []);

            return $contract;
        });

        return (new ContractResource($contract->load(self::FULL)))->response()->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        $this->authorize('view', $contract);

        return new ContractResource($contract->load(self::FULL));
    }

    /** PATCH — `lines` envoyées : remplacent la liste. */
    public function update(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('update', $contract);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($contract, $data) {
            $contract->update(array_diff_key($data, ['lines' => true]));
            if (array_key_exists('lines', $data)) {
                $contract->lines()->delete();
                $this->writeLines($contract, $data['lines']);
            }
        });

        return new ContractResource($contract->refresh()->load(self::FULL));
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $this->authorize('delete', $contract);
        $contract->delete();

        return response()->json(['message' => 'Contrat supprimé.']);
    }

    /** POST /api/v1/contracts/{id}/invoice — facture brouillon de la prochaine période. */
    public function invoice(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);
        $this->authorize('create', Invoice::class);
        abort_unless($contract->status === ContractStatus::Active, 422, 'Seul un contrat en cours se facture.');
        $contract->load('lines');
        abort_if($contract->lines->isEmpty(), 422, "Ce contrat n'a aucune ligne à facturer.");
        $start = ($contract->next_invoice_date ?? $contract->start_date)->copy();
        abort_if($contract->end_date && $start->gt($contract->end_date), 422, 'Le contrat est arrivé à échéance : toutes ses périodes sont facturées.');
        $end = $contract->billing_period->periodEnd($start);
        if ($contract->end_date && $end->gt($contract->end_date)) {
            $end = $contract->end_date->copy();
        }
        abort_if(Invoice::query()->where('contract_id', $contract->id)->whereDate('period_start', $start)->where('status', '!=', 'cancelled')->exists(), 422, 'Cette période a déjà sa facture.');

        $invoice = DB::transaction(function () use ($contract, $start, $end, $request) {
            $invoice = InvoiceBuilder::blank([
                'client_id' => $contract->client_id,
                'project_id' => $contract->project_id,
                'title' => "{$contract->title} — du {$start->format('d/m/Y')} au {$end->format('d/m/Y')}",
                'introduction' => "Contrat {$contract->number} — période du {$start->format('d/m/Y')} au {$end->format('d/m/Y')} (facturation {$contract->billing_period->label()}).",
            ], $request->user());
            $invoice->forceFill(['contract_id' => $contract->id, 'period_start' => $start, 'period_end' => $end])->saveQuietly();
            InvoiceBuilder::writeLines($invoice, $contract->lines->map(fn ($l) => [
                'designation' => $l->designation, 'unit' => $l->unit, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price,
            ])->all());
            $contract->forceFill(['next_invoice_date' => $end->copy()->addDay()])->save();

            return $invoice;
        });

        return (new InvoiceResource($invoice->refresh()->load(InvoiceController::FULL)))->response()->setStatusCode(201);
    }

    public function pdf(Request $request, Contract $contract): Response
    {
        $this->authorize('view', $contract);
        abort_unless($request->user()->can('prices.view'), 403, "Vous n'avez pas le droit de voir les montants de ce contrat.");
        $pdf = QuotePdf::contract($contract);
        $name = "Contrat {$contract->number}.pdf";

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    // --- Forfaits ------------------------------------------------------------------------

    public function flatRates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);
        $prices = $request->user()->can('prices.view');
        $rates = FlatRate::query()->with('client:id,name')
            ->when($request->filled('client_id'), fn ($q) => $q->where(fn ($w) => $w->where('client_id', $request->integer('client_id'))->orWhereNull('client_id')))
            ->orderBy('designation')->get();

        return response()->json(['data' => $rates->map(fn ($r) => [
            'id' => $r->id, 'designation' => $r->designation, 'description' => $r->description, 'unit' => $r->unit,
            'client_id' => $r->client_id, 'client' => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name] : null,
            'article_id' => $r->article_id, ...($prices ? ['price' => $r->price] : []),
        ])]);
    }

    public function storeFlatRate(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);
        $rate = FlatRate::create($this->flatRateData($request, true));

        return response()->json(['data' => $rate->only(['id', 'designation', 'description', 'unit', 'client_id', 'article_id', 'price'])], 201);
    }

    public function updateFlatRate(Request $request, FlatRate $flatRate): JsonResponse
    {
        abort_unless($request->user()->can('contracts.update'), 403, "Vous n'avez pas le droit de faire cette action.");
        $flatRate->update($this->flatRateData($request, false));

        return response()->json(['data' => $flatRate->only(['id', 'designation', 'description', 'unit', 'client_id', 'article_id', 'price'])]);
    }

    public function destroyFlatRate(Request $request, FlatRate $flatRate): JsonResponse
    {
        abort_unless($request->user()->can('contracts.delete'), 403, "Vous n'avez pas le droit de faire cette action.");
        $flatRate->delete();

        return response()->json(['message' => 'Forfait supprimé.']);
    }

    /** Contrats dont l'échéance tombe dans leur préavis (calculé ici : compatible MariaDB et SQLite). */
    public static function endingIds(): array
    {
        return Contract::query()->whereNotNull('end_date')->whereDate('end_date', '>=', today())->get(['id', 'end_date', 'notice_days'])
            ->filter(fn ($c) => $c->end_date->lte(today()->addDays($c->notice_days)))->pluck('id')->all();
    }

    private function writeLines(Contract $contract, array $lines): void
    {
        foreach (array_values($lines) as $i => $l) {
            $rate = ! empty($l['flat_rate_id']) ? FlatRate::find($l['flat_rate_id']) : null;
            $contract->lines()->create([
                'flat_rate_id' => $rate?->id,
                'position' => $i,
                'designation' => trim((string) ($l['designation'] ?? '')) ?: ($rate?->designation ?? 'Prestation'),
                'unit' => $l['unit'] ?? $rate?->unit,
                'quantity' => $l['quantity'] ?? 1,
                'unit_price' => $l['unit_price'] ?? $rate?->price ?? 0,
            ]);
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'client_id' => [$req, 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'title' => [$req, 'string', 'max:255'],
            'status' => ['sometimes', new Enum(ContractStatus::class)],
            'start_date' => [$req, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'tacit_renewal' => ['sometimes', 'boolean'],
            'billing_period' => ['sometimes', new Enum(BillingPeriod::class)],
            'next_invoice_date' => ['sometimes', 'nullable', 'date'],
            'visits_per_year' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'notice_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'lines' => ['sometimes', 'array', 'max:100'],
            'lines.*.flat_rate_id' => ['nullable', 'integer', Rule::exists('flat_rates', 'id')->whereNull('deleted_at')],
            'lines.*.designation' => ['nullable', 'string', 'max:255', 'required_without:lines.*.flat_rate_id'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], ['end_date.after_or_equal' => "L'échéance doit suivre le début du contrat.", 'lines.*.designation.required_without' => 'Chaque ligne a besoin d\'un forfait ou d\'une désignation.'], [
            'client_id' => 'client', 'title' => 'titre', 'start_date' => 'début', 'end_date' => 'échéance', 'lines.*.unit_price' => 'prix',
        ]);
        // Sans prices.view : pas de prix saisis (ceux des forfaits s'appliquent)
        if (! $request->user()->can('prices.view') && isset($data['lines'])) {
            $data['lines'] = array_map(fn ($l) => array_diff_key($l, ['unit_price' => true]), $data['lines']);
        }

        return $data;
    }

    private function flatRateData(Request $request, bool $creating): array
    {
        return $request->validate([
            'designation' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'article_id' => ['sometimes', 'nullable', 'integer', Rule::exists('articles', 'id')],
        ], [], ['designation' => 'désignation', 'price' => 'prix']);
    }
}
