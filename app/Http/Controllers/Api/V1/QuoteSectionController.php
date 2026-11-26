<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteSection;
use App\Support\QuoteEditing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'is_visible' => ['sometimes', 'boolean'],
            // Texte de la proposition technique pour cette section (modifiable même sur un devis accepté)
            'proposal' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ], [], ['title' => 'titre de la section', 'proposal' => 'proposition technique']);
        if (array_diff(array_keys($data), ['proposal']) !== []) {
            QuoteEditing::ensureEditable($quote);
        }

        $section->update($data);
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

    /**
     * POST /api/v1/quote-sections/{id}/duplicate — copie la section et ses lignes (article, coût, coefficient,
     * commentaire, choix du quantitatif) et son texte de proposition technique.
     * Sans `quote_ids` : dans le même devis, juste après, titrée « (copie) ». Avec `quote_ids` : à la fin de
     * chacun de ces devis (brouillon ou envoyé, que l'on peut modifier), sous le même titre.
     * Réponse : le devis d'origine, et `meta.copied_to` (devis, numéro, section créée).
     */
    public function duplicate(Request $request, QuoteSection $section): QuoteResource
    {
        $source = $section->quote;
        $this->authorize('view', $source);
        $ids = $request->validate([
            'quote_ids' => ['nullable', 'array', 'max:20'],
            'quote_ids.*' => ['integer', 'distinct'],
        ], [], ['quote_ids' => 'devis'])['quote_ids'] ?? [];

        $targets = $ids === [] ? collect([$source]) : Quote::query()->whereIn('id', $ids)->get();
        abort_if($targets->count() !== max(1, count($ids)), 404, 'Devis introuvable.');
        foreach ($targets as $target) {
            $this->authorize('update', $target);
            QuoteEditing::ensureEditable($target);
        }

        $section->load('lines');
        $copies = DB::transaction(function () use ($section, $source, $targets) {
            return $targets->map(function (Quote $target) use ($section, $source) {
                $same = $target->is($source);
                if ($same) {
                    // Juste après la section d'origine
                    $target->sections()->where('position', '>', $section->position)->increment('position');
                    $position = $section->position + 1;
                } else {
                    $position = (int) $target->sections()->max('position') + 1;
                }
                $copy = $target->sections()->create([
                    'title' => $same ? mb_substr($section->title.' (copie)', 0, 255) : $section->title,
                    'position' => $position,
                    'is_visible' => $section->is_visible,
                    'proposal' => $section->proposal,
                ]);
                foreach ($section->lines as $line) {
                    $copy->lines()->make($line->only([
                        'position', 'article_id', 'designation', 'reference', 'comment', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient',
                    ]))->forceFill(['show_in_quantities' => $line->show_in_quantities])->save();
                }
                $target->recalculate();

                return ['quote_id' => $target->id, 'number' => $target->number, 'title' => $target->title, 'section_id' => $copy->id];
            })->values()->all();
        });

        return (new QuoteResource($source->refresh()->load(QuoteEditing::FULL)))->additional(['meta' => ['copied_to' => $copies]]);
    }
}
