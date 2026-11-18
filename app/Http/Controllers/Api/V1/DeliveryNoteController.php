<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryNoteType;
use App\Enums\QuoteLineCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\DeliveryNote;
use App\Models\StockMovement;
use App\Support\Stock;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\DocumentNumber;
use App\Support\QuoteEditing;
use App\Support\QuotePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bordereaux de livraison ou de travaux (Dash3 : invoice_bls), numérotés BL-2026-0001, sans prix.
 * Par défaut, reprennent les équipements du devis (centrale, organes, câble), comme Dash3.
 */
class DeliveryNoteController extends Controller
{
    private const EQUIPMENT = [QuoteLineCategory::Central, QuoteLineCategory::Device, QuoteLineCategory::Cable];

    public function store(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        $data = $this->validated($request, true);

        DB::transaction(function () use ($quote, $data, $request) {
            $note = new DeliveryNote([
                'title' => $data['title'] ?? $quote->title,
                'delivered_at' => $data['delivered_at'] ?? now()->toDateString(),
                ...array_diff_key($data, ['lines' => true]),
            ]);
            $note->number = DocumentNumber::next('delivery_note', $note->delivered_at);
            $note->created_by = $request->user()->id;
            $quote->deliveryNotes()->save($note);

            $lines = $data['lines'] ?? $this->equipmentOf($quote);
            foreach (array_values($lines) as $position => $line) {
                $note->lines()->create([...$line, 'position' => $position]);
            }
        });

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    /** PATCH /api/v1/delivery-notes/{id} — `lines` envoyées : elles remplacent celles du bordereau. */
    public function update(Request $request, DeliveryNote $note): QuoteResource
    {
        $quote = $note->quote;
        $this->authorize('update', $quote);
        $data = $this->validated($request, false);
        abort_if($note->stock_released_at && array_key_exists('lines', $data), 422, 'Ce bordereau est sorti du stock : remettez-le en stock avant de changer ses lignes.');

        DB::transaction(function () use ($note, $data) {
            $note->update(array_diff_key($data, ['lines' => true]));
            if (array_key_exists('lines', $data)) {
                $note->lines()->delete();
                foreach (array_values($data['lines']) as $position => $line) {
                    $note->lines()->create([...$line, 'position' => $position]);
                }
            }
        });

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    public function destroy(DeliveryNote $note): QuoteResource
    {
        $quote = $note->quote;
        $this->authorize('update', $quote);
        abort_if($note->stock_released_at !== null, 422, 'Ce bordereau est sorti du stock : remettez-le en stock avant de le supprimer.');
        $note->delete();

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    /**
     * POST /api/v1/delivery-notes/{id}/release — matériel livré : les lignes liées à un article
     * sortent du stock (projet du devis). Refusé si un article manque, sans rien sortir.
     */
    public function release(Request $request, DeliveryNote $note): QuoteResource
    {
        $this->authorizeStock($request, $note);
        abort_if($note->stock_released_at !== null, 422, 'Ce bordereau est déjà sorti du stock.');
        $needs = $note->lines()->whereNotNull('article_id')->where('quantity', '>', 0)->get()
            ->groupBy('article_id')->map(fn ($lines) => $lines->sum('quantity'));
        abort_if($needs->isEmpty(), 422, 'Aucune ligne de ce bordereau n\'est liée à un article du stock.');

        DB::transaction(function () use ($note, $needs, $request) {
            $articles = Article::withTrashed()->whereIn('id', $needs->keys())->lockForUpdate()->get()->keyBy('id');
            $missing = $needs->filter(fn ($qty, $id) => $articles[$id]->quantity < $qty)
                ->map(fn ($qty, $id) => $articles[$id]->designation.' ('.Stock::format($articles[$id]->quantity).' en stock, '.Stock::format($qty).' à sortir)');
            abort_if($missing->isNotEmpty(), 422, 'Stock insuffisant : '.$missing->implode(' ; ').'.');

            foreach ($needs as $articleId => $qty) {
                Stock::move($articles[$articleId], -$qty, StockMovementType::Delivery, $request->user(), [
                    'reason' => "Bordereau {$note->number}", 'source' => $note, 'project_id' => $note->quote->project_id,
                ]);
            }
            $note->forceFill(['stock_released_at' => now(), 'stock_released_by' => $request->user()->id])->save();
        });

        return new QuoteResource($note->quote->refresh()->load(QuoteEditing::FULL));
    }

    /** POST /api/v1/delivery-notes/{id}/restock — annule la sortie : les articles reviennent en stock. */
    public function restock(Request $request, DeliveryNote $note): QuoteResource
    {
        $this->authorizeStock($request, $note);
        abort_if($note->stock_released_at === null, 422, 'Ce bordereau n\'est pas sorti du stock.');

        DB::transaction(function () use ($note, $request) {
            $moved = StockMovement::where(['source_type' => $note->getMorphClass(), 'source_id' => $note->id])
                ->get()->groupBy('article_id')->map(fn ($m) => $m->sum('quantity'));
            foreach ($moved as $articleId => $qty) {
                if ($qty < 0) {
                    Stock::move(Article::withTrashed()->findOrFail($articleId), -$qty, StockMovementType::Delivery, $request->user(), [
                        'reason' => "Retour en stock — bordereau {$note->number}", 'source' => $note, 'project_id' => $note->quote->project_id,
                    ]);
                }
            }
            $note->forceFill(['stock_released_at' => null, 'stock_released_by' => null])->save();
        });

        return new QuoteResource($note->quote->refresh()->load(QuoteEditing::FULL));
    }

    /** Magasinier (stock.move) ou qui peut modifier le devis. */
    private function authorizeStock(Request $request, DeliveryNote $note): void
    {
        abort_unless(
            $request->user()->can('stock.move') || $request->user()->can('update', $note->quote),
            403,
            "Vous n'avez pas le droit de sortir ce matériel du stock.",
        );
    }

    /** GET /api/v1/delivery-notes/{id}/pdf — sans prix : visible par qui voit le devis. */
    public function pdf(Request $request, DeliveryNote $note): Response
    {
        $this->authorize('view', $note->quote);
        $pdf = QuotePdf::deliveryNote($note);
        $filename = $note->number.'.pdf';

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** @return list<array<string, mixed>> lignes d'équipement des sections visibles du devis */
    private function equipmentOf(Quote $quote): array
    {
        return QuoteLine::query()
            ->whereIn('quote_section_id', $quote->sections()->where('is_visible', true)->select('id'))
            ->whereIn('category', array_map(fn ($c) => $c->value, self::EQUIPMENT))
            ->where('quantity', '>', 0)
            ->join('quote_sections', 'quote_sections.id', '=', 'quote_lines.quote_section_id')
            ->orderBy('quote_sections.position')->orderBy('quote_lines.position')
            ->get(['quote_lines.article_id', 'quote_lines.designation', 'quote_lines.reference', 'quote_lines.unit', 'quote_lines.quantity'])
            ->map(fn ($l) => $l->only(['article_id', 'designation', 'reference', 'unit', 'quantity']))
            ->all();
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => ['sometimes', new Enum(DeliveryNoteType::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivered_at' => ['sometimes', 'date'],
            'done' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'todo' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'show_logo' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.article_id' => ['nullable', 'integer', Rule::exists('articles', 'id')],
            'lines.*.designation' => ['required', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ], [], [
            'type' => 'type', 'title' => 'titre', 'delivered_at' => 'date', 'done' => 'travaux réalisés', 'todo' => 'reste à faire',
            'lines.*.designation' => 'désignation', 'lines.*.quantity' => 'quantité',
        ]);
    }
}
