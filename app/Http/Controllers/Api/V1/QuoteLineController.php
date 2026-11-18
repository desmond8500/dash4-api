<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteLineForm;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteSection;
use App\Support\QuoteEditing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lignes d'un devis ; chaque réponse renvoie le devis complet, totaux recalculés. */
class QuoteLineController extends Controller
{
    public function store(QuoteLineForm $request, QuoteSection $section): QuoteResource
    {
        $quote = $section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $data = $request->validated();
        QuoteEditing::ensureCanEditPrices($request->user(), $data);
        unset($data['quote_section_id']);

        $section->lines()->create([...$data, 'position' => $data['position'] ?? (int) $section->lines()->max('position') + 1]);
        $quote->recalculate();

        return $this->respond($quote);
    }

    public function update(QuoteLineForm $request, QuoteLine $line): QuoteResource
    {
        $quote = $line->section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $data = $request->validated();
        QuoteEditing::ensureCanEditPrices($request->user(), $data);

        if (isset($data['quote_section_id'])) {
            $line->quote_section_id = $data['quote_section_id'];
            unset($data['quote_section_id']);
        }
        $line->fill($data)->save();
        $quote->recalculate();

        return $this->respond($quote);
    }

    public function destroy(QuoteLine $line): QuoteResource
    {
        $quote = $line->section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);

        $line->delete();
        $quote->recalculate();

        return $this->respond($quote);
    }

    /** POST /api/v1/quote-sections/{id}/lines/order — `ids` dans le nouvel ordre. */
    public function reorder(Request $request, QuoteSection $section): QuoteResource
    {
        $quote = $section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('quote_lines', 'id')->where('quote_section_id', $section->id)],
        ])['ids'];

        foreach (array_values($ids) as $position => $id) {
            QuoteLine::whereKey($id)->update(['position' => $position]);
        }

        return $this->respond($quote);
    }

    private function respond(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }
}
