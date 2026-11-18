<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Marques des articles. Une marque avec des articles ne se supprime pas. */
class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);
        $brands = Brand::query()->withCount('articles')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->get();

        return response()->json(['data' => $brands->map(fn (Brand $b) => $this->present($b))]);
    }

    /** GET /api/v1/brands/{id} — ses articles : GET /api/v1/articles?brand_id=… */
    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json(['data' => $this->present($brand->loadCount('articles'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Brand::class);
        $brand = Brand::create($this->validated($request));

        return response()->json(['data' => $this->present($brand->loadCount('articles'))], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $brand->update($this->validated($request, $brand));

        return response()->json(['data' => $this->present($brand->loadCount('articles'))]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);
        abort_if($brand->articles()->exists(), 422, 'Cette marque a des articles : changez leur marque avant de la supprimer.');
        $brand->delete();

        return response()->json(['message' => 'Marque supprimée.']);
    }

    private function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => [$brand ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($brand?->id)->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
        ], ['name.unique' => 'Cette marque existe déjà.'], ['name' => 'nom', 'website' => 'site web']);
    }

    private function present(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'description' => $brand->description,
            'website' => $brand->website,
            'logo_url' => $brand->logo_path ? Storage::disk('public')->url($brand->logo_path) : null,
            'articles_count' => $brand->articles_count ?? 0,
            'can' => [
                'update' => (bool) request()->user()?->can('update', $brand),
                'delete' => (bool) request()->user()?->can('delete', $brand),
            ],
        ];
    }
}
