<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fournisseurs (Dash3 : providers). */
class ProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Provider::class);
        $query = Provider::query()->withCount('articles')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('contact_name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->orderBy('name');
        SyncQuery::apply($query, $request);
        $page = $query->paginate(SyncQuery::perPage($request, 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Provider $p) => $this->present($p)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), ...SyncQuery::meta()['meta']],
        ]);
    }

    public function show(Provider $provider): JsonResponse
    {
        $this->authorize('view', $provider);

        return response()->json(['data' => $this->present($provider->loadCount('articles'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Provider::class);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = Provider::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return response()->json(['data' => $this->present($existing->loadCount('articles'))]);
        }
        $provider = new Provider($data);
        $provider->created_by = $request->user()->id;
        $provider->save();

        return response()->json(['data' => $this->present($provider->loadCount('articles'))], 201);
    }

    public function update(Request $request, Provider $provider): JsonResponse
    {
        $this->authorize('update', $provider);
        $provider->update(array_diff_key($this->validated($request, false), ['client_uuid' => true]));

        return response()->json(['data' => $this->present($provider->loadCount('articles'))]);
    }

    public function destroy(Provider $provider): JsonResponse
    {
        $this->authorize('delete', $provider);
        $provider->delete();

        return response()->json(['message' => 'Fournisseur supprimé.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], ['name' => 'nom', 'contact_name' => 'contact', 'phone' => 'téléphone', 'website' => 'site web']);
    }

    private function present(Provider $p): array
    {
        return [
            ...$p->only(['id', 'client_uuid', 'name', 'contact_name', 'phone', 'email', 'address', 'website', 'description']),
            'articles_count' => $p->articles_count ?? 0,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'deleted_at' => $p->deleted_at?->toIso8601String(),
        ];
    }
}
