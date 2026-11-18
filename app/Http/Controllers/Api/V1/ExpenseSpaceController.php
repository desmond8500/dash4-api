<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResources;
use App\Models\ExpenseSpace;
use App\Models\ExpenseSpaceFunding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Caisses (espaces de dépenses partagés) : membres, versements, solde.
 * Création, versements, membres : accounting.create / update ; les membres voient leur caisse.
 */
class ExpenseSpaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['accounting.view', 'accounting.view.own']), 403);
        $spaces = ExpenseSpace::query()->visibleTo($request->user())->with('members:id,firstname,lastname')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $spaces->map(fn ($s) => $this->present($s, $request))]);
    }

    public function show(Request $request, ExpenseSpace $space): JsonResponse
    {
        $this->ensureVisible($request, $space);

        return response()->json(['data' => $this->present($space->load(['members:id,firstname,lastname', 'fundings.creator:id,firstname,lastname']), $request, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.create'), 403);
        $data = $this->validated($request, true);
        $space = DB::transaction(function () use ($data, $request) {
            $space = new ExpenseSpace(array_diff_key($data, ['member_ids' => true]));
            $space->created_by = $request->user()->id;
            $space->save();
            $space->members()->sync($data['member_ids'] ?? []);

            return $space;
        });

        return response()->json(['data' => $this->present($space->load('members:id,firstname,lastname'), $request, true)], 201);
    }

    /** PATCH — `member_ids` envoyés : remplacent les membres. */
    public function update(Request $request, ExpenseSpace $space): JsonResponse
    {
        abort_unless($request->user()->can('accounting.update'), 403);
        $data = $this->validated($request, false);
        $space->update(array_diff_key($data, ['member_ids' => true]));
        if (array_key_exists('member_ids', $data)) {
            $space->members()->sync($data['member_ids']);
        }

        return $this->show($request, $space->refresh());
    }

    public function destroy(Request $request, ExpenseSpace $space): JsonResponse
    {
        abort_unless($request->user()->can('accounting.delete'), 403);
        abort_if($space->expenses()->exists() || $space->fundings()->exists(), 422, 'Cette caisse a des versements ou des dépenses : désactivez-la plutôt.');
        $space->delete();

        return response()->json(['message' => 'Caisse supprimée.']);
    }

    /** POST /api/v1/expense-spaces/{id}/fundings — versement qui alimente la caisse. */
    public function fund(Request $request, ExpenseSpace $space): JsonResponse
    {
        abort_unless($request->user()->can('accounting.create'), 403);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'funded_at' => ['sometimes', 'date', 'before_or_equal:today'],
            'method' => ['sometimes', 'nullable', new Enum(PaymentMethod::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'funded_at' => 'date', 'method' => 'moyen de paiement']);
        $funding = new ExpenseSpaceFunding([...$data, 'funded_at' => $data['funded_at'] ?? now()->toDateString()]);
        $funding->created_by = $request->user()->id;
        $space->fundings()->save($funding);

        return $this->show($request, $space->refresh());
    }

    public function removeFunding(Request $request, ExpenseSpaceFunding $funding): JsonResponse
    {
        abort_unless($request->user()->can('accounting.delete'), 403);
        $space = ExpenseSpace::findOrFail($funding->expense_space_id);
        $funding->delete();

        return $this->show($request, $space);
    }

    private function ensureVisible(Request $request, ExpenseSpace $space): void
    {
        abort_unless(ExpenseSpace::query()->visibleTo($request->user())->whereKey($space->id)->exists(), 403, "Vous n'êtes pas membre de cette caisse.");
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ], [], ['name' => 'nom', 'member_ids' => 'membres']);
    }

    private function present(ExpenseSpace $space, Request $request, bool $full = false): array
    {
        $user = $request->user();

        return [
            'id' => $space->id,
            'name' => $space->name,
            'description' => $space->description,
            'is_active' => $space->is_active,
            ...$space->balance(),
            'members' => $space->members->map(fn ($m) => AttachmentResources::author($m))->values(),
            ...($full ? ['fundings' => $space->fundings->map(fn ($f) => [
                'id' => $f->id,
                'amount' => $f->amount,
                'funded_at' => $f->funded_at?->toDateString(),
                'method' => $f->method ? ['value' => $f->method->value, 'label' => $f->method->label()] : null,
                'note' => $f->note,
                'creator' => AttachmentResources::author($f->creator),
            ])->values()] : []),
            'can' => [
                'update' => $user->can('accounting.update'),
                'fund' => $user->can('accounting.create'),
                'delete' => $user->can('accounting.delete'),
            ],
        ];
    }
}
