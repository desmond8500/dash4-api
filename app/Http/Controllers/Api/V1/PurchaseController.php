<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseResource;
use App\Models\Article;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Support\DocumentNumber;
use App\Support\QuotePdf;
use App\Support\Settings;
use App\Support\Stock;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/**
 * Achats : brouillon → commandé → reçu en partie → reçu (ou annulé).
 * À la réception, les lignes liées à un article entrent en stock, et le coût de l'article
 * prend le dernier prix d'achat (au choix).
 */
class PurchaseController extends Controller
{
    private const FULL = ['provider', 'project:id,name', 'lines.article', 'receptions.receiver:id,firstname,lastname', 'creator:id,firstname,lastname'];

    /** GET /api/v1/purchases — filtres : status (liste), open=1 (à recevoir), provider_id, project_id, search. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Purchase::class);
        $query = Purchase::query()->visibleTo($request->user())->with(['provider:id,name', 'project:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('open'), fn ($q) => $q->whereIn('status', [PurchaseStatus::Ordered->value, PurchaseStatus::PartiallyReceived->value]))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhere('title', 'like', $term)
                    ->orWhereHas('provider', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->latest('id');
        SyncQuery::apply($query, $request);

        return PurchaseResource::collection($query->paginate(SyncQuery::perPage($request)))->additional(SyncQuery::meta());
    }

    /**
     * POST /api/v1/purchases — `provider_id`, `project_id`, `title`, `expected_at`, `tax_mode` (vat | none),
     * `lines` facultatives ; `low_stock=1` : ajoute les articles de ce fournisseur sous leur seuil
     * (quantité pour revenir à deux fois le seuil).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Purchase::class);
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->whereNull('deleted_at')],
            ...$this->headerRules(),
            'low_stock' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array', 'max:500'],
            ...$this->lineRules('lines.*.', true),
        ], [], $this->attributes());

        if (! empty($data['client_uuid']) && $existing = Purchase::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return $this->full($existing)->response()->setStatusCode(200);
        }
        $lines = $data['lines'] ?? [];
        $this->ensureCanSetCosts($request, $lines);

        $purchase = DB::transaction(function () use ($data, $lines, $request) {
            $purchase = new Purchase(array_diff_key($data, ['lines' => true, 'low_stock' => true]));
            $purchase->number = DocumentNumber::next('purchase_order');
            $purchase->vat_rate = (float) Settings::get('vat_rate');
            $purchase->created_by = $request->user()->id;
            $purchase->save();

            if (! empty($data['low_stock'])) {
                $lines = [...$lines, ...Article::query()->where('provider_id', $purchase->provider_id)->where('is_active', true)->low()
                    ->orderBy('designation')->get()
                    ->map(fn (Article $a) => ['article_id' => $a->id, 'quantity' => max(1, ceil($a->min_quantity * 2 - $a->quantity))])->all()];
            }
            foreach (array_values($lines) as $position => $line) {
                $purchase->lines()->create([...$this->fromArticle($line, $request), 'position' => $position]);
            }
            $purchase->recalculate();

            return $purchase;
        });

        return $this->full($purchase)->response()->setStatusCode(201);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        $this->authorize('view', $purchase);

        return $this->full($purchase);
    }

    /** PATCH /api/v1/purchases/{id} — brouillon : tout ; ensuite : date prévue et notes. */
    public function update(Request $request, Purchase $purchase): PurchaseResource
    {
        $this->authorize('update', $purchase);
        $data = $request->validate([
            'provider_id' => ['sometimes', 'integer', Rule::exists('providers', 'id')->whereNull('deleted_at')],
            ...$this->headerRules(),
        ], [], $this->attributes());
        if (array_diff(array_keys($data), ['expected_at', 'notes']) !== []) {
            $this->ensureDraft($purchase);
        }
        $purchase->update($data);
        $purchase->recalculate();

        return $this->full($purchase);
    }

    public function addLine(Request $request, Purchase $purchase): PurchaseResource
    {
        $this->authorize('update', $purchase);
        $this->ensureDraft($purchase);
        $data = $request->validate($this->lineRules('', true), [], $this->attributes());
        $this->ensureCanSetCosts($request, [$data]);
        $purchase->lines()->create([...$this->fromArticle($data, $request), 'position' => (int) $purchase->lines()->max('position') + 1]);
        $purchase->recalculate();

        return $this->full($purchase);
    }

    public function updateLine(Request $request, PurchaseLine $line): PurchaseResource
    {
        $purchase = Purchase::findOrFail($line->purchase_id);
        $this->authorize('update', $purchase);
        $this->ensureDraft($purchase);
        $data = $request->validate($this->lineRules('', false), [], $this->attributes());
        $this->ensureCanSetCosts($request, [$data]);
        $line->update($data);
        $purchase->recalculate();

        return $this->full($purchase);
    }

    public function removeLine(PurchaseLine $line): PurchaseResource
    {
        $purchase = Purchase::findOrFail($line->purchase_id);
        $this->authorize('update', $purchase);
        $this->ensureDraft($purchase);
        $line->delete();
        $purchase->recalculate();

        return $this->full($purchase);
    }

    /** POST /api/v1/purchases/{id}/order — commande passée au fournisseur (`ordered_at`, aujourd'hui par défaut). */
    public function order(Request $request, Purchase $purchase): PurchaseResource
    {
        $this->authorize('order', $purchase);
        $this->ensureDraft($purchase);
        $data = $request->validate(['ordered_at' => ['sometimes', 'date'], 'expected_at' => ['sometimes', 'nullable', 'date']], [], $this->attributes());
        abort_if($purchase->lines()->doesntExist(), 422, 'Ajoutez au moins une ligne avant de commander.');
        $purchase->forceFill([
            'status' => PurchaseStatus::Ordered,
            'ordered_at' => $data['ordered_at'] ?? now()->toDateString(),
            ...(array_key_exists('expected_at', $data) ? ['expected_at' => $data['expected_at']] : []),
        ])->save();

        return $this->full($purchase);
    }

    /** POST /api/v1/purchases/{id}/cancel — seulement si rien n'a été reçu. */
    public function cancel(Purchase $purchase): PurchaseResource
    {
        $this->authorize('order', $purchase);
        abort_unless(in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true), 422, "Cet achat est « {$purchase->status->label()} » : il ne s'annule plus.");
        $purchase->forceFill(['status' => PurchaseStatus::Cancelled])->save();

        return $this->full($purchase);
    }

    /**
     * POST /api/v1/purchases/{id}/receive — `lines` : [{id, quantity}] (absent : tout le reste),
     * `received_at`, `note`, `update_costs` (oui par défaut : le coût des articles prend le prix d'achat).
     */
    public function receive(Request $request, Purchase $purchase): PurchaseResource
    {
        $this->authorize('receive', $purchase);
        abort_unless($purchase->status->receivable(), 422, $purchase->isDraft()
            ? 'Commandez cet achat avant de le réceptionner.'
            : "Cet achat est « {$purchase->status->label()} » : rien à réceptionner.");
        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer', 'distinct', Rule::exists('purchase_lines', 'id')->where('purchase_id', $purchase->id)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'received_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
            'update_costs' => ['sometimes', 'boolean'],
        ], [], ['lines.*.quantity' => 'quantité reçue', 'received_at' => 'date de réception']);

        DB::transaction(function () use ($purchase, $data, $request) {
            $lines = $purchase->lines()->lockForUpdate()->get()->keyBy('id');
            $wanted = isset($data['lines'])
                ? collect($data['lines'])->mapWithKeys(fn ($l) => [$l['id'] => (float) $l['quantity']])
                : $lines->mapWithKeys(fn (PurchaseLine $l) => [$l->id => $l->remaining()]);
            $wanted = $wanted->filter(fn ($q) => $q > 0);
            abort_if($wanted->isEmpty(), 422, 'Indiquez au moins une quantité reçue.');

            $over = $wanted->filter(fn ($q, $id) => $q > $lines[$id]->remaining() + 0.0001)
                ->map(fn ($q, $id) => $lines[$id]->designation.' (reste '.Stock::format($lines[$id]->remaining()).')');
            abort_if($over->isNotEmpty(), 422, 'Plus que commandé : '.$over->implode(' ; ').'.');

            $at = isset($data['received_at']) ? now()->parse($data['received_at']) : now();
            $updateCosts = $data['update_costs'] ?? true;
            $received = [];
            foreach ($wanted as $id => $qty) {
                $line = $lines[$id];
                $line->forceFill(['received_quantity' => round($line->received_quantity + $qty, 2)])->save();
                $received[] = ['purchase_line_id' => $line->id, 'article_id' => $line->article_id, 'designation' => $line->designation, 'quantity' => $qty];
                if ($line->article_id && $article = Article::withTrashed()->find($line->article_id)) {
                    Stock::move($article, $qty, StockMovementType::Purchase, $request->user(), [
                        'reason' => "Achat {$purchase->number}", 'source' => $purchase, 'unit_cost' => $line->unit_cost,
                        'project_id' => $purchase->project_id, 'moved_at' => $at,
                    ]);
                    if ($updateCosts && $line->unit_cost > 0) {
                        $article->forceFill(['unit_cost' => $line->unit_cost])->save();
                    }
                }
            }

            $purchase->receptions()->create(['received_at' => $at, 'lines' => $received, 'note' => $data['note'] ?? null, 'received_by' => $request->user()->id]);
            $complete = $purchase->lines()->get()->every(fn (PurchaseLine $l) => $l->remaining() <= 0);
            $purchase->forceFill([
                'status' => $complete ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived,
                'received_at' => $complete ? $at : null,
            ])->save();
        });

        return $this->full($purchase);
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        $this->authorize('delete', $purchase);
        $purchase->delete();

        return response()->json(['message' => 'Brouillon d\'achat supprimé.']);
    }

    /** GET /api/v1/purchases/{id}/pdf — bon de commande (montants avec costs.view). */
    public function pdf(Request $request, Purchase $purchase): Response
    {
        $this->authorize('view', $purchase);
        abort_unless($request->user()->can('costs.view'), 403, "Vous n'avez pas accès aux prix d'achat.");
        $pdf = QuotePdf::purchaseOrder($purchase);

        $name = PdfName::purchase($purchase->loadMissing('provider'));

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    /** Ligne choisie dans le stock : désignation, référence, unité et coût de l'article, sauf ce qui est envoyé. */
    private function fromArticle(array $line, Request $request): array
    {
        if (empty($line['article_id'])) {
            return $line;
        }
        $article = Article::findOrFail($line['article_id']);

        return [
            'designation' => $article->designation,
            'reference' => $article->reference,
            'unit' => $article->unit,
            ...($request->user()->can('costs.view') ? ['unit_cost' => $article->unit_cost] : []),
            ...$line,
        ];
    }

    private function ensureCanSetCosts(Request $request, array $lines): void
    {
        $withCost = collect($lines)->contains(fn ($l) => array_key_exists('unit_cost', $l));
        abort_if($withCost && ! $request->user()->can('costs.view'), 403, "Vous ne pouvez pas saisir les prix d'achat : le comptable les complétera.");
    }

    private function ensureDraft(Purchase $purchase): void
    {
        abort_unless($purchase->isDraft(), 422, "Cet achat est « {$purchase->status->label()} » : ses lignes ne se modifient plus.");
    }

    private function headerRules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expected_at' => ['sometimes', 'nullable', 'date'],
            'tax_mode' => ['sometimes', Rule::in(['vat', 'none'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    private function lineRules(string $prefix, bool $creating): array
    {
        return [
            "{$prefix}article_id" => ['sometimes', 'nullable', 'integer', Rule::exists('articles', 'id')->whereNull('deleted_at')],
            "{$prefix}designation" => [$creating ? "required_without:{$prefix}article_id" : 'sometimes', 'string', 'max:255'],
            "{$prefix}reference" => ['sometimes', 'nullable', 'string', 'max:2000'],
            "{$prefix}unit" => ['sometimes', 'nullable', 'string', 'max:20'],
            "{$prefix}quantity" => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0.01', 'max:9999999'],
            "{$prefix}unit_cost" => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    private function attributes(): array
    {
        return [
            'provider_id' => 'fournisseur', 'project_id' => 'projet', 'title' => 'objet', 'expected_at' => 'date de livraison prévue',
            'ordered_at' => 'date de commande', 'tax_mode' => 'taxe', 'designation' => 'désignation', 'quantity' => 'quantité',
            'unit_cost' => "prix d'achat", 'lines.*.designation' => 'désignation', 'lines.*.quantity' => 'quantité',
        ];
    }

    private function full(Purchase $purchase): PurchaseResource
    {
        return new PurchaseResource($purchase->refresh()->load(self::FULL));
    }
}
