<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catégories de dépenses : visibles par qui a la comptabilité, gérées avec accounting.update. */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['accounting.view', 'accounting.view.own']), 403);
        $categories = ExpenseCategory::query()->withCount('expenses')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $categories->map(fn ($c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.update'), 403);
        $category = ExpenseCategory::create($this->validated($request, null));

        return response()->json(['data' => $this->present($category->loadCount('expenses'))], 201);
    }

    public function update(Request $request, ExpenseCategory $category): JsonResponse
    {
        abort_unless($request->user()->can('accounting.update'), 403);
        $category->update($this->validated($request, $category));

        return response()->json(['data' => $this->present($category->loadCount('expenses'))]);
    }

    /** Une catégorie utilisée se désactive plutôt que de se supprimer. */
    public function destroy(Request $request, ExpenseCategory $category): JsonResponse
    {
        abort_unless($request->user()->can('accounting.delete'), 403);
        abort_if($category->expenses()->exists(), 422, 'Cette catégorie a des dépenses : désactivez-la plutôt.');
        $category->delete();

        return response()->json(['message' => 'Catégorie supprimée.']);
    }

    private function validated(Request $request, ?ExpenseCategory $category): array
    {
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($category?->id)->whereNull('deleted_at')],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'Cette catégorie existe déjà.', 'color.regex' => 'Couleur au format #RRGGBB.'], ['name' => 'nom', 'color' => 'couleur']);
    }

    private function present(ExpenseCategory $c): array
    {
        return [...$c->only(['id', 'name', 'color', 'is_active']), 'expenses_count' => $c->expenses_count ?? 0];
    }
}
