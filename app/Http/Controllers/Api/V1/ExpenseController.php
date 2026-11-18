<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Document;
use App\Models\Expense;
use App\Models\ExpenseSpace;
use App\Notifications\ExpenseNotification;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Dépenses : saisies par chacun (accounting.create.own) puis validées par le comptable,
 * ou saisies et validées d'un coup par le comptable (accounting.create).
 * Justificatif (photo, PDF) en pièce jointe : envoyé avec la dépense ou plus tard.
 */
class ExpenseController extends Controller
{
    private const FULL = ['category', 'space:id,name', 'project:id,name', 'quote:id,number', 'provider:id,name', 'document', 'creator:id,firstname,lastname', 'decider:id,firstname,lastname'];

    /**
     * GET /api/v1/expenses — filtres : status (liste), mine=1, to_reimburse=1, expense_space_id, project_id,
     * quote_id, expense_category_id, created_by, from, to, search ; synchro : updated_since.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expense::class);
        $user = $request->user();
        $query = Expense::query()->visibleTo($user)->with(self::FULL)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $user->id))
            ->when($request->boolean('to_reimburse'), fn ($q) => $q->where('advanced', true)->where('status', ExpenseStatus::Approved->value))
            ->when($request->filled('expense_space_id'), fn ($q) => $q->where('expense_space_id', $request->integer('expense_space_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('quote_id'), fn ($q) => $q->where('quote_id', $request->integer('quote_id')))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('created_by'), fn ($q) => $q->where('created_by', $request->integer('created_by')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('spent_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('spent_at', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where('label', 'like', '%'.$request->string('search').'%'))
            ->latest('spent_at')->orderByDesc('id');
        SyncQuery::apply($query, $request);

        return ExpenseResource::collection($query->paginate(SyncQuery::perPage($request, 50)))->additional(SyncQuery::meta());
    }

    /** GET /api/v1/expenses/summary — à valider, à rembourser, ce mois-ci (dans ce que l'on voit). */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);
        $visible = fn () => Expense::query()->visibleTo($request->user());

        return response()->json(['data' => [
            'to_approve_count' => $visible()->where('status', ExpenseStatus::Submitted->value)->count(),
            'to_approve_amount' => (int) $visible()->where('status', ExpenseStatus::Submitted->value)->sum('amount'),
            'to_reimburse_count' => $visible()->where('advanced', true)->where('status', ExpenseStatus::Approved->value)->count(),
            'to_reimburse_amount' => (int) $visible()->where('advanced', true)->where('status', ExpenseStatus::Approved->value)->sum('amount'),
            'this_month' => (int) $visible()->whereIn('status', ExpenseStatus::counted())
                ->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'),
        ]]);
    }

    /** POST /api/v1/expenses — multipart possible, avec `file` (justificatif). */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Expense::class);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = Expense::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new ExpenseResource($existing->load(self::FULL)))->response()->setStatusCode(200);
        }
        $user = $request->user();
        $this->ensureSpaceAllowed($request, $data['expense_space_id'] ?? null);

        $expense = DB::transaction(function () use ($data, $request, $user) {
            $expense = new Expense(array_diff_key($data, ['file' => true]));
            $expense->created_by = $user->id;
            if ($user->can('accounting.create')) {
                $expense->forceFill(['status' => ExpenseStatus::Approved, 'decided_by' => $user->id, 'decided_at' => now()]);
            }
            $expense->save();
            if ($request->hasFile('file')) {
                $this->attachReceipt($request, $expense);
            }

            return $expense;
        });

        if ($expense->isSubmitted()) {
            ExpenseNotification::submitted($expense->load('creator'));
        }

        return (new ExpenseResource($expense->refresh()->load(self::FULL)))->response()->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return new ExpenseResource($expense->load(self::FULL));
    }

    /** PATCH /api/v1/expenses/{id} (ou POST en multipart pour remplacer le justificatif). */
    public function update(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('update', $expense);
        $data = array_diff_key($this->validated($request, false), ['client_uuid' => true]);
        if (array_key_exists('expense_space_id', $data)) {
            $this->ensureSpaceAllowed($request, $data['expense_space_id']);
        }
        $expense->update(array_diff_key($data, ['file' => true]));
        if ($request->hasFile('file')) {
            $this->attachReceipt($request, $expense);
        }

        return new ExpenseResource($expense->refresh()->load(self::FULL));
    }

    /** POST /api/v1/expenses/{id}/receipt — justificatif ajouté après coup (mobile, retour du réseau). */
    public function receipt(Request $request, Expense $expense): ExpenseResource
    {
        abort_unless($request->user()->can('update', $expense) || $expense->created_by === $request->user()->id, 403);
        $request->validate(['file' => ['required', ...$this->fileRules()]], [], ['file' => 'justificatif']);
        $this->attachReceipt($request, $expense);

        return new ExpenseResource($expense->refresh()->load(self::FULL));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);
        $expense->delete();

        return response()->json(['message' => 'Dépense supprimée.']);
    }

    public function approve(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('decide', $expense);
        abort_unless(in_array($expense->status, [ExpenseStatus::Submitted, ExpenseStatus::Rejected], true), 422, "Cette dépense est « {$expense->status->label()} ».");
        $expense->forceFill(['status' => ExpenseStatus::Approved, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'rejection_reason' => null])->save();
        ExpenseNotification::toAuthor($expense->load('creator'), 'approved', $request->user());

        return new ExpenseResource($expense->refresh()->load(self::FULL));
    }

    public function reject(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('decide', $expense);
        abort_unless(in_array($expense->status, [ExpenseStatus::Submitted, ExpenseStatus::Approved], true), 422, "Cette dépense est « {$expense->status->label()} ».");
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Indiquez pourquoi la dépense est refusée.'], ['reason' => 'motif'])['reason'];
        $expense->forceFill(['status' => ExpenseStatus::Rejected, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'rejection_reason' => $reason])->save();
        ExpenseNotification::toAuthor($expense->load('creator'), 'rejected', $request->user());

        return new ExpenseResource($expense->refresh()->load(self::FULL));
    }

    /** POST /api/v1/expenses/{id}/reimburse — dépense avancée et validée : remboursée à son auteur. */
    public function reimburse(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('decide', $expense);
        abort_unless($expense->toReimburse(), 422, $expense->advanced ? 'Validez la dépense avant de la rembourser.' : "Cette dépense n'a pas été avancée par la personne.");
        $expense->forceFill(['status' => ExpenseStatus::Reimbursed, 'reimbursed_by' => $request->user()->id, 'reimbursed_at' => now()])->save();
        ExpenseNotification::toAuthor($expense->load('creator'), 'reimbursed', $request->user());

        return new ExpenseResource($expense->refresh()->load(self::FULL));
    }

    /** Une caisse : en être membre, ou voir toute la comptabilité. */
    private function ensureSpaceAllowed(Request $request, ?int $spaceId): void
    {
        if ($spaceId === null || $request->user()->can('accounting.view')) {
            return;
        }
        abort_unless(ExpenseSpace::find($spaceId)?->hasMember($request->user()), 422, "Vous n'êtes pas membre de cette caisse.");
    }

    private function attachReceipt(Request $request, Expense $expense): void
    {
        $file = $request->file('file');
        $document = $expense->documents()->make([
            'name' => 'Justificatif — '.$expense->label.'.'.strtolower($file->getClientOriginalExtension()),
            'path' => $file->store("documents/expenses/{$expense->id}", Document::DISK),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $document->created_by = $request->user()->id;
        $document->save();
        $expense->forceFill(['document_id' => $document->id])->save();
    }

    private function fileRules(): array
    {
        return ['file', 'max:20480', 'extensions:pdf,jpg,jpeg,png,webp,heic'];
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'label' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'amount' => [$required, 'integer', 'min:1', 'max:999999999999'],
            'spent_at' => [$required, 'date', 'before_or_equal:today'],
            'expense_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'expense_space_id' => ['sometimes', 'nullable', 'integer', Rule::exists('expense_spaces', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'quote_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quotes', 'id')->whereNull('deleted_at')],
            'provider_id' => ['sometimes', 'nullable', 'integer', Rule::exists('providers', 'id')->whereNull('deleted_at')],
            'payment_method' => ['sometimes', 'nullable', new Enum(PaymentMethod::class)],
            'advanced' => ['sometimes', 'boolean'],
            'is_paid' => ['sometimes', 'boolean'],
            'file' => ['sometimes', ...$this->fileRules()],
        ], [], [
            'label' => 'libellé', 'amount' => 'montant', 'spent_at' => 'date', 'expense_category_id' => 'catégorie',
            'expense_space_id' => 'caisse', 'project_id' => 'projet', 'quote_id' => 'devis', 'provider_id' => 'fournisseur',
            'payment_method' => 'moyen de paiement', 'file' => 'justificatif',
        ]);
    }
}
