<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResources;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Transactions saisies à la main (apport, emprunt, salaires, loyer, impôts…) : accounting.*. */
class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.view'), 403);
        $page = Transaction::query()->with(['category:id,name', 'project:id,name', 'client:id,name', 'provider:id,name', 'creator:id,firstname,lastname'])
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transacted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transacted_at', '<=', $request->date('to')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->latest('transacted_at')->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return response()->json([
            'data' => collect($page->items())->map(fn ($t) => $this->present($t)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.create'), 403);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = Transaction::where('client_uuid', $data['client_uuid'])->first()) {
            return response()->json(['data' => $this->present($existing)]);
        }
        $transaction = new Transaction($data);
        $transaction->created_by = $request->user()->id;
        $transaction->save();

        return response()->json(['data' => $this->present($transaction->refresh())], 201);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('accounting.update'), 403);
        $transaction->update(array_diff_key($this->validated($request, false), ['client_uuid' => true]));

        return response()->json(['data' => $this->present($transaction->refresh())]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('accounting.delete'), 403);
        $transaction->delete();

        return response()->json(['message' => 'Transaction supprimée.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'direction' => [$required, Rule::in(['in', 'out'])],
            'label' => [$required, 'string', 'max:255'],
            'amount' => [$required, 'integer', 'min:1', 'max:999999999999'],
            'transacted_at' => [$required, 'date'],
            'expense_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'provider_id' => ['sometimes', 'nullable', 'integer', Rule::exists('providers', 'id')],
            'method' => ['sometimes', 'nullable', new Enum(PaymentMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], ['direction' => 'sens', 'label' => 'libellé', 'amount' => 'montant', 'transacted_at' => 'date', 'expense_category_id' => 'catégorie']);
    }

    private function present(Transaction $t): array
    {
        $t->loadMissing(['category:id,name', 'project:id,name', 'client:id,name', 'provider:id,name', 'creator:id,firstname,lastname']);

        return [
            ...$t->only(['id', 'client_uuid', 'direction', 'label', 'amount', 'expense_category_id', 'project_id', 'client_id', 'provider_id', 'reference', 'note']),
            'transacted_at' => $t->transacted_at?->toDateString(),
            'method' => $t->method ? ['value' => $t->method->value, 'label' => $t->method->label()] : null,
            'category' => $t->category?->only(['id', 'name']),
            'project' => $t->project?->only(['id', 'name']),
            'client' => $t->client?->only(['id', 'name']),
            'provider' => $t->provider?->only(['id', 'name']),
            'creator' => AttachmentResources::author($t->creator),
        ];
    }
}
