<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteLineCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteSection;
use App\Models\QuoteSystem;
use App\Models\QuoteTemplate;
use App\Support\QuoteEditing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Systèmes et modèles de devis (Dash3 : invoice_systems, invoice_models).
 * Consultation et insertion : quotes.create ou quotes.update. Gestion : quotes.update + costs.view
 * (les modèles portent des coûts et des coefficients).
 */
class QuoteTemplateController extends Controller
{
    /** GET /api/v1/quote-systems — systèmes avec leurs modèles (sans les lignes). */
    public function systems(Request $request): JsonResponse
    {
        $this->allowUse($request);

        $systems = QuoteSystem::query()->orderBy('name')
            ->with(['templates' => fn ($q) => $q->withCount('lines')])
            ->get();
        $loose = QuoteTemplate::query()->whereNull('quote_system_id')->withCount('lines')->orderBy('name')->get();

        return response()->json(['data' => [
            'systems' => $systems->map(fn (QuoteSystem $s) => [
                ...$s->only(['id', 'name', 'description', 'icon']),
                'templates' => $s->templates->map(fn ($t) => self::templateSummary($t))->values(),
            ])->values(),
            'templates_without_system' => $loose->map(fn ($t) => self::templateSummary($t))->values(),
        ]]);
    }

    public function storeSystem(Request $request): JsonResponse
    {
        $this->allowManage($request);
        $system = QuoteSystem::create($this->validateSystem($request, true));

        return response()->json(['data' => $system->only(['id', 'name', 'description', 'icon'])], 201);
    }

    public function updateSystem(Request $request, QuoteSystem $system): JsonResponse
    {
        $this->allowManage($request);
        $system->update($this->validateSystem($request, false));

        return response()->json(['data' => $system->only(['id', 'name', 'description', 'icon'])]);
    }

    /** Supprimer un système garde ses modèles (ils passent « sans système »). */
    public function destroySystem(Request $request, QuoteSystem $system): JsonResponse
    {
        $this->allowManage($request);
        $system->templates()->update(['quote_system_id' => null]);
        $system->delete();

        return response()->json(['message' => 'Système supprimé.']);
    }

    /** GET /api/v1/quote-templates/{id} — modèle et ses lignes (coûts : costs.view). */
    public function show(Request $request, QuoteTemplate $template): JsonResponse
    {
        $this->allowUse($request);

        return response()->json(['data' => $this->full($request, $template)]);
    }

    /** POST /api/v1/quote-templates — `lines` : lignes du modèle. */
    public function store(Request $request): JsonResponse
    {
        $this->allowManage($request);
        $data = $this->validateTemplate($request, true);

        $template = DB::transaction(function () use ($data, $request) {
            $template = new QuoteTemplate(array_diff_key($data, ['lines' => true]));
            $template->created_by = $request->user()->id;
            $template->save();
            $this->replaceLines($template, $data['lines'] ?? []);

            return $template;
        });

        return response()->json(['data' => $this->full($request, $template)], 201);
    }

    /** PATCH /api/v1/quote-templates/{id} — `lines` envoyées : elles remplacent celles du modèle. */
    public function update(Request $request, QuoteTemplate $template): JsonResponse
    {
        $this->allowManage($request);
        $data = $this->validateTemplate($request, false);

        DB::transaction(function () use ($template, $data) {
            $template->update(array_diff_key($data, ['lines' => true]));
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($template, $data['lines']);
            }
        });

        return response()->json(['data' => $this->full($request, $template->refresh())]);
    }

    public function destroy(Request $request, QuoteTemplate $template): JsonResponse
    {
        $this->allowManage($request);
        $template->delete();

        return response()->json(['message' => 'Modèle supprimé.']);
    }

    /** POST /api/v1/quote-sections/{id}/save-as-template — enregistre les lignes d'une section comme modèle. */
    public function fromSection(Request $request, QuoteSection $section): JsonResponse
    {
        $this->allowManage($request);
        $this->authorize('view', $section->quote);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quote_system_id' => ['nullable', 'integer', Rule::exists('quote_systems', 'id')->whereNull('deleted_at')],
        ], [], ['name' => 'nom du modèle', 'quote_system_id' => 'système']);

        $template = DB::transaction(function () use ($data, $section, $request) {
            $template = new QuoteTemplate($data);
            $template->created_by = $request->user()->id;
            $template->save();
            foreach ($section->lines as $position => $line) {
                $template->lines()->create([
                    ...$line->only(['article_id', 'designation', 'reference', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient']),
                    'position' => $position,
                ]);
            }

            return $template;
        });

        return response()->json(['data' => $this->full($request, $template)], 201);
    }

    /**
     * POST /api/v1/quotes/{id}/insert-template — `template_id`, et `quote_section_id` pour ajouter
     * les lignes à une section existante ; sinon une nouvelle section au nom du modèle.
     * Les coûts du modèle sont repris, même pour un compte qui ne voit pas les coûts.
     */
    public function insert(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        QuoteEditing::ensureEditable($quote);
        $data = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('quote_templates', 'id')->whereNull('deleted_at')],
            'quote_section_id' => ['nullable', 'integer', Rule::exists('quote_sections', 'id')->where('quote_id', $quote->id)],
        ], [], ['template_id' => 'modèle', 'quote_section_id' => 'section']);
        $template = QuoteTemplate::with('lines')->findOrFail($data['template_id']);

        DB::transaction(function () use ($quote, $template, $data) {
            $section = isset($data['quote_section_id'])
                ? QuoteSection::findOrFail($data['quote_section_id'])
                : $quote->sections()->create(['title' => $template->name, 'position' => (int) $quote->sections()->max('position') + 1]);
            $start = (int) $section->lines()->max('position') + 1;
            foreach ($template->lines as $i => $line) {
                $section->lines()->create([...$line->toQuoteLine(), 'position' => $start + $i]);
            }
            $quote->recalculate();
        });

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    private function replaceLines(QuoteTemplate $template, array $lines): void
    {
        $template->lines()->delete();
        foreach (array_values($lines) as $position => $line) {
            $template->lines()->create([...$line, 'position' => $position]);
        }
    }

    private function validateSystem(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
        ], [], ['name' => 'nom du système']);
    }

    private function validateTemplate(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'quote_system_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quote_systems', 'id')->whereNull('deleted_at')],
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.designation' => ['required', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:2000'],
            'lines.*.category' => ['sometimes', new Enum(QuoteLineCategory::class)],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.coefficient' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
        ], [], ['name' => 'nom du modèle', 'quote_system_id' => 'système', 'lines.*.designation' => 'désignation']);
    }

    private static function templateSummary(QuoteTemplate $t): array
    {
        return [...$t->only(['id', 'quote_system_id', 'name', 'description', 'icon']), 'lines_count' => $t->lines_count ?? $t->lines()->count()];
    }

    private function full(Request $request, QuoteTemplate $template): array
    {
        $costs = $request->user()->can('costs.view');
        $prices = $request->user()->can('prices.view');

        return [
            ...$template->only(['id', 'quote_system_id', 'name', 'description', 'icon']),
            'system' => $template->system?->only(['id', 'name']),
            'lines' => $template->lines()->get()->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'designation' => $l->designation,
                'reference' => $l->reference,
                'category' => ['value' => $l->category->value, 'label' => $l->category->label()],
                'unit' => $l->unit,
                'quantity' => $l->quantity,
                ...($prices ? ['unit_price' => (int) round($l->unit_cost * $l->coefficient)] : []),
                ...($costs ? ['unit_cost' => $l->unit_cost, 'coefficient' => $l->coefficient] : []),
            ])->values(),
        ];
    }

    private function allowUse(Request $request): void
    {
        abort_unless($request->user()->canAny(['quotes.create', 'quotes.update', 'quotes.update.own']), 403, "Vous n'avez pas le droit de faire cette action.");
    }

    private function allowManage(Request $request): void
    {
        abort_unless(
            $request->user()->can('quotes.update') && $request->user()->can('costs.view'),
            403,
            'Seuls ceux qui voient les coûts (commercial, manager, administrateur) gèrent les modèles de devis.',
        );
    }
}
