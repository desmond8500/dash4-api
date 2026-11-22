<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Article;
use App\Models\StockMovement;
use App\Support\Stock;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Mouvements de stock : journal, et saisie à la main (entrée, sortie, inventaire) avec stock.move. */
class StockMovementController extends Controller
{
    /** GET /api/v1/stock-movements — filtres : article_id, project_id, type, from, to. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Article::class);
        $query = StockMovement::query()->with(['article:id,designation,reference,unit', 'project:id,name', 'source', 'creator:id,firstname,lastname'])
            ->when($request->filled('article_id'), fn ($q) => $q->where('article_id', $request->integer('article_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', explode(',', (string) $request->string('type'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('moved_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('moved_at', '<=', $request->date('to')))
            // Recherche mot par mot : article (désignation, référence), motif, projet
            ->when($request->filled('search'), function ($q) use ($request) {
                foreach (preg_split('/\s+/', trim($request->string('search')->toString())) as $word) {
                    $term = '%'.$word.'%';
                    $q->where(fn ($w) => $w->where('reason', 'like', $term)
                        ->orWhereHas('article', fn ($a) => $a->withTrashed()->where(fn ($x) => $x->where('designation', 'like', $term)->orWhere('reference', 'like', $term)))
                        ->orWhereHas('project', fn ($p) => $p->where('name', 'like', $term)));
                }
            })
            ->latest('moved_at')->orderByDesc('id');

        return StockMovementResource::collection($query->paginate(SyncQuery::perPage($request, 50)));
    }

    /**
     * POST /api/v1/articles/{id}/movements — `type` in | out | inventory, `quantity`
     * (pour un inventaire : la quantité comptée), `reason`, `project_id`, `client_uuid` (hors ligne).
     */
    public function store(Request $request, Article $article): JsonResponse
    {
        $this->authorize('move', $article);
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'type' => ['required', Rule::in(array_map(fn ($t) => $t->value, StockMovementType::manual()))],
            'quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'moved_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ], [], ['type' => 'type de mouvement', 'quantity' => 'quantité', 'reason' => 'motif', 'project_id' => 'projet', 'moved_at' => 'date']);

        if (! empty($data['client_uuid']) && StockMovement::where('client_uuid', $data['client_uuid'])->exists()) {
            return $this->respond($article, 200);
        }

        $type = StockMovementType::from($data['type']);
        abort_if($type !== StockMovementType::Inventory && (float) $data['quantity'] <= 0, 422, 'Indiquez une quantité.');
        $options = [
            'reason' => $data['reason'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'client_uuid' => $data['client_uuid'] ?? null,
            'moved_at' => $data['moved_at'] ?? now(),
        ];

        match ($type) {
            StockMovementType::In => Stock::move($article, (float) $data['quantity'], $type, $request->user(), $options),
            StockMovementType::Out => Stock::move($article, -(float) $data['quantity'], $type, $request->user(), $options),
            default => Stock::count($article, (float) $data['quantity'], $request->user(), $options),
        };

        return $this->respond($article, 201);
    }

    private function respond(Article $article, int $status): JsonResponse
    {
        return (new ArticleResource($article->refresh()->load([
            'brand:id,name', 'provider:id,name', 'storageLocation:id,name,code', 'dependencies',
            'movements' => fn ($q) => $q->limit(50)->with(['source', 'project:id,name', 'creator:id,firstname,lastname']),
        ])))->response()->setStatusCode($status);
    }
}
