<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryNoteType;
use App\Enums\QuoteLineCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\DocumentNumber;
use App\Support\QuoteEditing;
use App\Support\QuotePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $note->delete();

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
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
            ->get(['quote_lines.designation', 'quote_lines.reference', 'quote_lines.unit', 'quote_lines.quantity'])
            ->map(fn ($l) => $l->only(['designation', 'reference', 'unit', 'quantity']))
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
