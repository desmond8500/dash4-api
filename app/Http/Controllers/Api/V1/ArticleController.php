<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteLineCategory;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Support\Stock;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Articles du stock. La quantité ne se saisit pas : elle change par les mouvements
 * (quantité de départ possible à la création, enregistrée comme un inventaire).
 */
class ArticleController extends Controller
{
    /** GET /api/v1/articles — filtres : search, barcode, brand_id, provider_id, category, low=1, active (défaut 1). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Article::class);
        $query = Article::query()->with(['brand:id,name', 'provider:id,name', 'storageLocation:id,name,code'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('designation', 'like', $term)->orWhere('reference', 'like', $term)
                    ->orWhere('barcode', 'like', $term)->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $term))
                    ->orWhereHas('storageLocation', fn ($l) => $l->where('name', 'like', $term)));
            })
            ->when($request->filled('barcode'), fn ($q) => $q->where(fn ($w) => $w->where('barcode', $request->string('barcode'))->orWhere('reference', $request->string('barcode'))))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('storage_location_id'), fn ($q) => $q->where('storage_location_id', $request->integer('storage_location_id')))
            ->when($request->filled('category'), fn ($q) => $q->whereIn('category', explode(',', (string) $request->string('category'))))
            ->when($request->boolean('low'), fn ($q) => $q->low())
            ->when(! SyncQuery::isSync($request) && $request->input('active', '1') !== 'all', fn ($q) => $q->where('is_active', $request->boolean('active', true)))
            ->orderBy('designation')->orderBy('id');
        SyncQuery::apply($query, $request);

        return ArticleResource::collection($query->paginate(SyncQuery::perPage($request, 50)))->additional(SyncQuery::meta());
    }

    /** GET /api/v1/articles/summary — nombre d'articles, stock bas, valeur du stock (costs.view). */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Article::class);
        $active = Article::query()->where('is_active', true);

        return response()->json(['data' => [
            'articles' => (clone $active)->count(),
            'low' => (clone $active)->low()->count(),
            'out' => (clone $active)->where('quantity', '<=', 0)->count(),
            ...($request->user()->can('costs.view') ? ['stock_value' => (int) round((float) (clone $active)->where('quantity', '>', 0)->sum(DB::raw('quantity * unit_cost')))] : []),
        ]]);
    }

    public function show(Article $article): ArticleResource
    {
        $this->authorize('view', $article);

        return $this->full($article);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Article::class);
        $data = $this->validated($request, null);
        if (! empty($data['client_uuid']) && $existing = Article::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return $this->full($existing)->response()->setStatusCode(200);
        }
        $this->ensureCanEditCosts($request, $data);

        $article = DB::transaction(function () use ($data, $request) {
            $article = new Article(array_diff_key($data, ['initial_quantity' => true]));
            $article->created_by = $request->user()->id;
            $article->save();
            if (! empty($data['initial_quantity'])) {
                Stock::move($article, (float) $data['initial_quantity'], StockMovementType::Inventory, $request->user(), [
                    'reason' => 'Stock de départ', 'unit_cost' => $article->unit_cost,
                ]);
            }

            return $article;
        });

        return $this->full($article)->response()->setStatusCode(201);
    }

    public function update(Request $request, Article $article): ArticleResource
    {
        $this->authorize('update', $article);
        $data = array_diff_key($this->validated($request, $article), ['client_uuid' => true, 'initial_quantity' => true]);
        $this->ensureCanEditCosts($request, $data);
        $article->update($data);

        return $this->full($article);
    }

    /**
     * POST /api/v1/articles/{id}/duplicate — copie l'article (désignation, catégorie, coût, coefficient,
     * marque, fournisseur, emplacement, icône…) sans son code-barres, son image ni sa quantité (stock à 0).
     */
    public function duplicate(Request $request, Article $article): JsonResponse
    {
        $this->authorize('view', $article);
        $this->authorize('create', Article::class);

        $copy = DB::transaction(function () use ($article, $request) {
            $copy = new Article([
                ...$article->only([
                    'designation', 'description', 'category', 'unit', 'brand_id', 'provider_id',
                    'unit_cost', 'coefficient', 'min_quantity', 'storage_location_id', 'plan_icon_id', 'weight',
                ]),
                'designation' => $article->designation.' (copie)',
                'is_active' => true,
            ]);
            $copy->created_by = $request->user()->id;
            $copy->save();

            return $copy;
        });

        return $this->full($copy)->response()->setStatusCode(201);
    }

    /** Corbeille : l'article reste dans l'historique des mouvements et des devis. */
    public function destroy(Article $article): JsonResponse
    {
        $this->authorize('delete', $article);
        $article->delete();

        return response()->json(['message' => 'Article mis à la corbeille.']);
    }

    /** POST /api/v1/articles/{id}/image (multipart, champ `image`) */
    public function storeImage(Request $request, Article $article): ArticleResource
    {
        $this->authorize('update', $article);
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [], ['image' => 'image']);
        $old = $article->image_path;
        $article->forceFill(['image_path' => $request->file('image')->store('articles/images', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->full($article);
    }

    public function destroyImage(Article $article): ArticleResource
    {
        $this->authorize('update', $article);
        if ($article->image_path) {
            Storage::disk('public')->delete($article->image_path);
            $article->forceFill(['image_path' => null])->save();
        }

        return $this->full($article);
    }

    /** PUT /api/v1/articles/{id}/dependencies — `items` : [{article_id, quantity}], remplace la liste. */
    public function dependencies(Request $request, Article $article): ArticleResource
    {
        $this->authorize('update', $article);
        $items = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            'items.*.article_id' => ['required', 'integer', 'distinct', Rule::exists('articles', 'id')->whereNull('deleted_at'), Rule::notIn([$article->id])],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999'],
        ], ['items.*.article_id.not_in' => 'Un article ne peut pas dépendre de lui-même.'], ['items.*.article_id' => 'article', 'items.*.quantity' => 'quantité'])['items'];

        $article->dependencies()->sync(collect($items)->mapWithKeys(fn ($i) => [$i['article_id'] => ['quantity' => $i['quantity']]])->all());

        return $this->full($article);
    }

    private function ensureCanEditCosts(Request $request, array $data): void
    {
        if (array_intersect(array_keys($data), ['unit_cost', 'coefficient']) !== []) {
            abort_unless($request->user()->can('costs.view'), 403, 'Vous ne pouvez pas saisir les coûts.');
        }
    }

    private function validated(Request $request, ?Article $article): array
    {
        $creating = $article === null;

        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'designation' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('articles', 'barcode')->ignore($article?->id)->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', new Enum(QuoteLineCategory::class)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'provider_id' => ['sometimes', 'nullable', 'integer', Rule::exists('providers', 'id')->whereNull('deleted_at')],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'coefficient' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'min_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'storage_location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('storage_locations', 'id')->whereNull('deleted_at')],
            'plan_icon_id' => ['sometimes', 'nullable', 'integer', Rule::exists('plan_icons', 'id')->whereNull('deleted_at')],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
            'initial_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
        ], ['barcode.unique' => 'Ce code-barres est déjà celui d\'un autre article.'], [
            'designation' => 'désignation', 'reference' => 'référence', 'barcode' => 'code-barres', 'category' => 'catégorie',
            'brand_id' => 'marque', 'provider_id' => 'fournisseur', 'unit_cost' => 'coût', 'min_quantity' => 'seuil',
            'storage_location_id' => 'emplacement', 'plan_icon_id' => 'icône de plan', 'weight' => 'poids', 'initial_quantity' => 'quantité de départ',
        ]);
    }

    private function full(Article $article): ArticleResource
    {
        return new ArticleResource($article->refresh()->load([
            'brand:id,name', 'provider:id,name', 'storageLocation:id,name,code', 'dependencies',
            'movements' => fn ($q) => $q->limit(50)->with(['source', 'project:id,name', 'creator:id,firstname,lastname']),
        ]));
    }
}
