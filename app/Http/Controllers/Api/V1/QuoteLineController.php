<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteLineForm;
use App\Http\Resources\QuoteResource;
use App\Models\Article;
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
        $data = $this->withArticle($data, true);

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
        if (! empty($data['article_id']) && $data['article_id'] !== $line->article_id) {
            $data = $this->withArticle($data, false);
        }
        $line->fill($data)->save();
        $quote->recalculate();

        return $this->respond($quote);
    }

    /**
     * PATCH /api/v1/quote-lines/{id}/article — lie la ligne à un article du stock (ou la délie avec null)
     * sans rien changer à la ligne : ni désignation ni prix. Possible aussi sur un devis accepté.
     */
    public function link(Request $request, QuoteLine $line): QuoteResource
    {
        $quote = $line->section->quote;
        $this->authorize('update', $quote);
        $data = $request->validate([
            'article_id' => ['present', 'nullable', 'integer', Rule::exists('articles', 'id')->whereNull('deleted_at')],
        ], [], ['article_id' => 'article']);
        $line->forceFill(['article_id' => $data['article_id']])->saveQuietly();

        return $this->respond($quote);
    }

    /**
     * POST /api/v1/quotes/{id}/link-articles — lie les lignes sans article aux articles du stock de même
     * référence (ou, à défaut, de même désignation), sans changer les lignes. Renvoie le devis et le nombre de liens.
     */
    public function linkAll(Quote $quote): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $quote);
        $norm = fn (?string $v) => $v === null ? '' : mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
        $lines = QuoteLine::query()->whereIn('quote_section_id', $quote->sections()->pluck('id'))->whereNull('article_id')->get();
        $refs = $lines->map(fn ($l) => $norm($l->reference))->filter()->unique();
        $names = $lines->map(fn ($l) => $norm($l->designation))->filter()->unique();
        $articles = Article::query()
            ->where(fn ($q) => $q->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(reference))'), $refs->all())->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(designation))'), $names->all()))
            ->get(['id', 'reference', 'designation']);
        $byRef = $articles->filter(fn ($a) => $norm($a->reference) !== '')->groupBy(fn ($a) => $norm($a->reference));
        $byName = $articles->groupBy(fn ($a) => $norm($a->designation));
        $linked = 0;
        foreach ($lines as $line) {
            // Un seul article correspondant, sinon on ne devine pas
            $match = ($byRef[$norm($line->reference)] ?? collect())->count() === 1 ? $byRef[$norm($line->reference)]->first()
                : (($byName[$norm($line->designation)] ?? collect())->count() === 1 ? $byName[$norm($line->designation)]->first() : null);
            if ($match) {
                $line->forceFill(['article_id' => $match->id])->saveQuietly();
                $linked++;
            }
        }

        return response()->json([
            'data' => new QuoteResource($quote->refresh()->load(QuoteEditing::FULL)),
            'linked' => $linked,
            'message' => $linked ? "{$linked} ligne(s) liée(s) à un article du stock." : 'Aucune ligne ne correspond à un seul article du stock (même référence ou même désignation).',
        ]);
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

    /**
     * Article du stock choisi : désignation, référence, unité, catégorie, coût et coefficient
     * de l'article, sauf ce qui est envoyé (à la création) ; coût et coefficient repris à chaque changement d'article.
     */
    private function withArticle(array $data, bool $creating): array
    {
        if (empty($data['article_id'])) {
            return $data;
        }
        $article = Article::findOrFail($data['article_id']);
        $fromArticle = [
            'designation' => $article->designation,
            'reference' => $article->reference,
            'unit' => $article->unit,
            'category' => $article->category,
            'unit_cost' => $article->unit_cost,
            'coefficient' => $article->coefficient,
        ];

        return $creating ? [...$fromArticle, ...$data] : [...$data, 'unit_cost' => $article->unit_cost, 'coefficient' => $article->coefficient];
    }

    private function respond(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }
}
