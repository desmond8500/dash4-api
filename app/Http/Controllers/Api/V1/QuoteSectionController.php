<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteSection;
use App\Support\QuoteEditing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sections d'un devis ; chaque réponse renvoie le devis complet, totaux à jour. */
class QuoteSectionController extends Controller
{
    public function store(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'is_visible' => ['sometimes', 'boolean'],
        ], [], ['title' => 'titre de la section']);

        $quote->sections()->create([...$data, 'position' => (int) $quote->sections()->max('position') + 1]);

        return $this->respond($quote);
    }

    public function update(Request $request, QuoteSection $section): QuoteResource
    {
        $quote = $section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);

        $section->update($request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'is_visible' => ['sometimes', 'boolean'],
        ], [], ['title' => 'titre de la section']));
        if ($section->wasChanged('is_visible')) {
            $quote->recalculate();
        }

        return $this->respond($quote);
    }

    public function destroy(QuoteSection $section): QuoteResource
    {
        $quote = $section->quote;
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);

        $section->delete();
        $quote->recalculate();

        return $this->respond($quote);
    }

    /** POST /api/v1/quotes/{id}/sections/order — `ids` dans le nouvel ordre. */
    public function reorder(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('quote_sections', 'id')->where('quote_id', $quote->id)],
        ])['ids'];

        foreach (array_values($ids) as $position => $id) {
            QuoteSection::whereKey($id)->update(['position' => $position]);
        }

        return $this->respond($quote);
    }

    private function respond(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }
}
