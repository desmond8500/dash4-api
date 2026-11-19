<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StorageLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Emplacements du stock : stock.view pour consulter (avec le nombre d'articles et de pièces rangées),
 * stock.create / update / delete pour les gérer. Les articles d'un emplacement : GET /articles?storage_location_id=…
 */
class StorageLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.view'), 403);
        $locations = StorageLocation::query()
            ->withCount(['articles' => fn ($q) => $q->where('is_active', true)])
            ->withSum(['articles as units' => fn ($q) => $q->where('is_active', true)->where('quantity', '>', 0)], 'quantity')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('search').'%')->orWhere('code', $request->string('search'))))
            ->orderBy('name')->get();

        return response()->json(['data' => $locations->map(fn ($l) => $this->present($l, $request))]);
    }

    public function show(Request $request, StorageLocation $location): JsonResponse
    {
        abort_unless($request->user()->can('stock.view'), 403);

        return response()->json(['data' => $this->present($this->counted($location), $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.create'), 403);
        $location = StorageLocation::create($this->validated($request, null));

        return response()->json(['data' => $this->present($this->counted($location), $request)], 201);
    }

    public function update(Request $request, StorageLocation $location): JsonResponse
    {
        abort_unless($request->user()->can('stock.update'), 403);
        $location->update($this->validated($request, $location));

        return response()->json(['data' => $this->present($this->counted($location), $request)]);
    }

    public function destroy(Request $request, StorageLocation $location): JsonResponse
    {
        abort_unless($request->user()->can('stock.delete'), 403);
        abort_if($location->articles()->exists(), 422, 'Des articles sont rangés ici : changez leur emplacement, ou désactivez celui-ci.');
        $location->delete();

        return response()->json(['message' => 'Emplacement supprimé.']);
    }

    private function counted(StorageLocation $location): StorageLocation
    {
        return $location->loadCount(['articles' => fn ($q) => $q->where('is_active', true)])
            ->loadSum(['articles as units' => fn ($q) => $q->where('is_active', true)->where('quantity', '>', 0)], 'quantity');
    }

    private function validated(Request $request, ?StorageLocation $location): array
    {
        return $request->validate([
            'name' => [$location ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('storage_locations', 'name')->ignore($location?->id)->whereNull('deleted_at')],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('storage_locations', 'code')->ignore($location?->id)->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'Cet emplacement existe déjà.', 'code.unique' => 'Ce code est déjà utilisé.'], ['name' => 'nom', 'code' => 'code']);
    }

    private function present(StorageLocation $l, Request $request): array
    {
        return [
            ...$l->only(['id', 'name', 'code', 'description', 'is_active']),
            'articles_count' => (int) ($l->articles_count ?? 0),
            'units' => (float) ($l->units ?? 0),
            'can' => ['update' => $request->user()->can('stock.update'), 'delete' => $request->user()->can('stock.delete')],
        ];
    }
}
