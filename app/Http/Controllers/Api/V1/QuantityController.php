<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuantityResource;
use App\Http\Resources\QuoteResource;
use App\Models\Article;
use App\Models\Project;
use App\Models\Quantity;
use App\Models\Room;
use App\Support\QuoteEditing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Quantitatifs d'un chantier : lignes (article ou désignation libre, quantité, pièce). Un quantitatif
 * devient un devis brouillon : une ligne par article (quantités additionnées), prix repris du stock.
 */
class QuantityController extends Controller
{
    private const WITH = ['rows.article:id,designation,reference', 'quote:id,number,title'];

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewSite', $project);

        return QuantityResource::collection($project->quantities()->with(self::WITH)->get());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
        $data = $this->validated($request, $project, true);

        $quantity = DB::transaction(function () use ($project, $data, $request) {
            $quantity = $project->quantities()->make(array_diff_key($data, ['rows' => true]));
            $quantity->created_by = $request->user()->id;
            $quantity->save();
            $this->writeRows($quantity, $data['rows'] ?? []);

            return $quantity;
        });

        return (new QuantityResource($quantity->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(Quantity $quantity): QuantityResource
    {
        $this->authorize('view', $quantity);

        return new QuantityResource($quantity->load(self::WITH));
    }

    /** PATCH — `rows` envoyées : remplacent la liste. */
    public function update(Request $request, Quantity $quantity): QuantityResource
    {
        $this->authorize('update', $quantity);
        $data = $this->validated($request, $quantity->project, false);
        DB::transaction(function () use ($quantity, $data) {
            $quantity->update(array_diff_key($data, ['rows' => true]));
            if (array_key_exists('rows', $data)) {
                $quantity->rows()->delete();
                $this->writeRows($quantity, $data['rows']);
            }
        });

        return new QuantityResource($quantity->refresh()->load(self::WITH));
    }

    public function destroy(Quantity $quantity): JsonResponse
    {
        $this->authorize('delete', $quantity);
        $quantity->delete();

        return response()->json(['message' => 'Quantitatif supprimé.']);
    }

    /** POST /api/v1/quantities/{id}/quote — crée un devis brouillon sur le projet (quotes.create). */
    public function toQuote(Request $request, Quantity $quantity): JsonResponse
    {
        $this->authorize('view', $quantity);
        abort_unless($request->user()->can('quotes.create'), 403, "Vous n'avez pas le droit de créer un devis.");
        $quantity->load('rows.article', 'project');
        abort_if($quantity->rows->isEmpty(), 422, 'Ce quantitatif est vide.');
        $project = $quantity->project;
        abort_unless($project->client_id, 422, "Le projet n'a pas de client : choisissez-en un avant de créer le devis.");

        $quote = DB::transaction(function () use ($quantity, $project, $request) {
            $quote = QuoteEditing::createDraft(['client_id' => $project->client_id, 'project_id' => $project->id, 'title' => $quantity->name], $request->user());
            $section = $quote->sections()->first();
            $section->update(['title' => $quantity->name]);
            $lines = $quantity->rows->groupBy(fn ($r) => $r->article_id ? "a{$r->article_id}" : 'd'.mb_strtolower(trim($r->designation)));
            $position = 0;
            foreach ($lines as $group) {
                $row = $group->first();
                /** @var Article|null $article */
                $article = $row->article;
                $section->lines()->create([
                    'position' => $position++,
                    'article_id' => $article?->id,
                    'designation' => $article?->designation ?? $row->designation,
                    'reference' => $article?->reference,
                    'unit' => $article?->unit ?? $row->unit,
                    'category' => $article?->category ?? 'other',
                    'quantity' => $group->sum('quantity'),
                    'unit_cost' => $article?->unit_cost ?? 0,
                    'coefficient' => $article?->coefficient ?? 1,
                ]);
            }
            $quote->recalculate();
            $quantity->forceFill(['quote_id' => $quote->id])->save();

            return $quote;
        });

        return (new QuoteResource($quote->refresh()->load(QuoteEditing::FULL)))->response()->setStatusCode(201);
    }

    private function writeRows(Quantity $quantity, array $rows): void
    {
        foreach (array_values($rows) as $i => $r) {
            $article = ! empty($r['article_id']) ? Article::find($r['article_id']) : null;
            $levelId = $r['level_id'] ?? null;
            if (! empty($r['room_id'])) {
                $levelId = Room::query()->whereKey($r['room_id'])->value('level_id');
            }
            $quantity->rows()->create([
                'article_id' => $article?->id,
                'designation' => trim((string) ($r['designation'] ?? '')) ?: ($article?->designation ?? 'Article'),
                'unit' => $r['unit'] ?? $article?->unit,
                'quantity' => $r['quantity'] ?? 1,
                'room_id' => $r['room_id'] ?? null,
                'level_id' => $levelId,
                'description' => $r['description'] ?? null,
                'position' => $i,
            ]);
        }
    }

    private function validated(Request $request, Project $project, bool $creating): array
    {
        $levelIds = \App\Models\Level::query()->whereIn('building_id', $project->buildings()->pluck('id'))->pluck('id');

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'building_id' => ['sometimes', 'nullable', 'integer', Rule::exists('buildings', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'installed_system_id' => ['sometimes', 'nullable', 'integer', Rule::exists('installed_systems', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'rows' => ['sometimes', 'array', 'max:500'],
            'rows.*.article_id' => ['nullable', 'integer', Rule::exists('articles', 'id')],
            'rows.*.designation' => ['nullable', 'string', 'max:255', 'required_without:rows.*.article_id'],
            'rows.*.unit' => ['nullable', 'string', 'max:20'],
            'rows.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rows.*.level_id' => ['nullable', 'integer', Rule::in($levelIds->all())],
            'rows.*.room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->whereIn('level_id', $levelIds)],
            'rows.*.description' => ['nullable', 'string', 'max:2000'],
        ], [
            'rows.*.designation.required_without' => 'Chaque ligne a besoin d\'un article ou d\'une désignation.',
            'rows.*.room_id.exists' => "Cette pièce n'est pas sur ce chantier.",
        ], ['name' => 'nom', 'rows.*.quantity' => 'quantité']);
    }
}
