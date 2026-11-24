<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ToolResource;
use App\Models\Article;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\User;
use App\Notifications\ToolLoanNotification;
use App\Support\Tools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Outillage (`tools.*`) : outils liés aux articles du stock, prêts à un utilisateur ou à une personne
 * extérieure, retours avec l'état constaté, rappels (automatiques chaque matin par `tools:remind`, ou à la main).
 */
class ToolController extends Controller
{
    private const LOAN_RELATIONS = ['borrower:id,firstname,lastname', 'lender:id,firstname,lastname', 'returner:id,firstname,lastname', 'project:id,name'];

    private static function full(): array
    {
        return [
            'article.brand:id,name',
            'storageLocation:id,name',
            'currentLoan' => fn ($q) => $q->with(self::LOAN_RELATIONS),
            'loans' => fn ($q) => $q->with(self::LOAN_RELATIONS)->limit(200),
        ];
    }

    /**
     * GET /api/v1/tools — filtres : status (available|lent|late|unavailable), article_id, borrower_id,
     * mine=1 (outils que j'ai en prêt), search (nom, numéro, n° de série, article, emprunteur).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tool::class);
        $user = $request->user();
        $tools = Tool::query()->visibleTo($user)
            ->with(['article.brand:id,name', 'storageLocation:id,name', 'currentLoan' => fn ($q) => $q->with(self::LOAN_RELATIONS)])
            ->when($request->filled('article_id'), fn ($q) => $q->where('article_id', $request->integer('article_id')))
            ->when($request->boolean('mine'), fn ($q) => $q->whereHas('loans', fn ($l) => $l->whereNull('returned_at')->where('borrower_id', $user->id)))
            ->when($request->filled('borrower_id'), fn ($q) => $q->whereHas('loans', fn ($l) => $l->whereNull('returned_at')->where('borrower_id', $request->integer('borrower_id'))))
            ->when($request->filled('search'), function ($q) use ($request) {
                foreach (preg_split('/\s+/', trim($request->string('search')->toString())) as $word) {
                    $term = '%'.$word.'%';
                    $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('serial_number', 'like', $term)
                        ->orWhereHas('article', fn ($a) => $a->where('designation', 'like', $term)->orWhere('reference', 'like', $term))
                        ->orWhereHas('loans', fn ($l) => $l->whereNull('returned_at')->where(fn ($b) => $b->where('borrower_name', 'like', $term)
                            ->orWhereHas('borrower', fn ($u) => $u->where('firstname', 'like', $term)->orWhere('lastname', 'like', $term)))));
                }
            })
            ->orderBy('name')->orderBy('code')
            ->get();

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            $today = today();
            $tools = $tools->filter(fn (Tool $t) => Tools::status($t, $today) === $status
                || ($status === 'lent' && Tools::status($t, $today) === 'late'))->values();
        }

        return ToolResource::collection($tools);
    }

    /** GET /api/v1/tools/options — états, et utilisateurs à qui prêter (avec `tools.lend`). */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tool::class);
        $list = fn (array $values) => collect($values)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();
        $borrowers = $request->user()->can('tools.lend')
            ? User::query()->where('is_active', true)->orderBy('firstname')->orderBy('lastname')->get(['id', 'firstname', 'lastname'])
                ->map(fn (User $u) => ['id' => $u->id, 'full_name' => $u->full_name])->values()
            : [];

        return response()->json(['data' => [
            'conditions' => $list(Tools::CONDITIONS),
            'statuses' => $list(Tools::STATUSES),
            'borrowers' => $borrowers,
        ]]);
    }

    /**
     * POST /api/v1/tools — `count` (1 à 50) crée plusieurs exemplaires d'un coup ; leurs numéros prennent
     * alors le suffixe -01, -02… Sans nom, l'outil prend la désignation de l'article.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tool::class);
        $data = $this->validated($request, null);
        $count = (int) ($request->validate(['count' => ['nullable', 'integer', 'min:1', 'max:50']])['count'] ?? 1);
        if (! $request->user()->can('costs.view')) {
            unset($data['purchase_value']);
        }
        if (blank($data['name'] ?? null)) {
            $article = isset($data['article_id']) ? Article::query()->find($data['article_id']) : null;
            abort_if($article === null, 422, "Donnez un nom à l'outil ou choisissez un article.");
            $data['name'] = $article->designation;
        }
        $code = $data['code'] ?? null;
        if ($count > 1 && $code) {
            $codes = collect(range(1, $count))->map(fn ($i) => $code.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $taken = Tool::withTrashed()->whereIn('code', $codes)->pluck('code');
            abort_if($taken->isNotEmpty(), 422, 'Numéros déjà utilisés : '.$taken->implode(', ').'.');
        }

        $tools = DB::transaction(fn () => collect(range(1, $count))->map(function ($i) use ($data, $count, $code, $request) {
            $tool = new Tool([...$data, 'code' => $count > 1 && $code ? $code.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT) : $code]);
            $tool->created_by = $request->user()->id;
            $tool->save();

            return $tool;
        }));

        if ($count > 1) {
            return ToolResource::collection(Tool::query()->whereKey($tools->pluck('id'))->with(self::full())->get())->response()->setStatusCode(201);
        }

        return (new ToolResource($tools->first()->load(self::full())))->response()->setStatusCode(201);
    }

    public function show(Tool $tool): ToolResource
    {
        $this->authorize('view', $tool);

        return new ToolResource($tool->load(self::full()));
    }

    public function update(Request $request, Tool $tool): ToolResource
    {
        $this->authorize('update', $tool);
        $data = $this->validated($request, $tool);
        if (! $request->user()->can('costs.view')) {
            unset($data['purchase_value']);
        }
        $tool->update($data);

        return new ToolResource($tool->refresh()->load(self::full()));
    }

    public function destroy(Tool $tool): JsonResponse
    {
        $this->authorize('delete', $tool);
        abort_if($tool->currentLoan()->exists(), 422, "L'outil est prêté : enregistrez son retour avant de le supprimer.");
        $tool->delete();

        return response()->json(['message' => 'Outil supprimé.']);
    }

    // --- Prêts ---------------------------------------------------------------------------------

    /** GET /api/v1/tool-loans — `open=1` prêts en cours, `late=1` en retard, borrower_id, tool_id. */
    public function loans(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tool::class);
        $user = $request->user();
        $today = today();
        $loans = ToolLoan::query()->with([...self::LOAN_RELATIONS, 'tool'])
            ->when(! $user->can('tools.view'), fn ($q) => $q->where('borrower_id', $user->id))
            ->when($request->boolean('open') || $request->boolean('late'), fn ($q) => $q->whereNull('returned_at'))
            ->when($request->boolean('late'), fn ($q) => $q->whereNotNull('due_on')->whereDate('due_on', '<', $today))
            ->when($request->filled('borrower_id'), fn ($q) => $q->where('borrower_id', $request->integer('borrower_id')))
            ->when($request->filled('tool_id'), fn ($q) => $q->where('tool_id', $request->integer('tool_id')))
            ->orderByRaw('CASE WHEN returned_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')->orderByDesc('lent_at')
            ->limit(500)->get();

        return response()->json(['data' => $loans->map(fn (ToolLoan $l) => ToolResource::loan($l, $today))->values()]);
    }

    /**
     * POST /api/v1/tools/{tool}/loans — prête l'outil à un utilisateur (`borrower_id`) ou à une personne
     * extérieure (`borrower_name`). L'emprunteur est prévenu. Rejoué avec le même `client_uuid` : rien n'est doublé.
     */
    public function lend(Request $request, Tool $tool): JsonResponse
    {
        $this->authorize('lend', $tool);
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'borrower_id' => ['nullable', 'required_without:borrower_name', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'borrower_name' => ['nullable', 'required_without:borrower_id', 'string', 'max:255'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'lent_at' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'borrower_id.required_without' => "Choisissez à qui l'outil est prêté.",
            'borrower_name.required_without' => "Choisissez à qui l'outil est prêté.",
        ]);

        if (! empty($data['client_uuid']) && ($existing = ToolLoan::query()->where('client_uuid', $data['client_uuid'])->first())) {
            return (new ToolResource($existing->tool->load(self::full())))->response()->setStatusCode(201);
        }
        abort_if($tool->currentLoan()->exists(), 422, "L'outil est déjà prêté : enregistrez d'abord son retour.");
        abort_if(in_array($tool->condition, Tools::UNAVAILABLE_CONDITIONS, true), 422,
            'L\'outil est indiqué « '.(Tools::CONDITIONS[$tool->condition] ?? $tool->condition).' » : changez son état avant de le prêter.');
        $lentAt = isset($data['lent_at']) ? \Illuminate\Support\Carbon::parse($data['lent_at']) : now();
        abort_if(isset($data['due_on']) && \Illuminate\Support\Carbon::parse($data['due_on'])->lt($lentAt->copy()->startOfDay()), 422,
            'La date de retour ne peut pas être avant la date du prêt.');

        $loan = new ToolLoan([...$data, 'lent_at' => $lentAt, 'borrower_name' => isset($data['borrower_id']) ? null : $data['borrower_name']]);
        $loan->forceFill(['tool_id' => $tool->id, 'created_by' => $request->user()->id])->save();

        $borrower = $loan->borrower;
        if ($borrower && $borrower->id !== $request->user()->id && $borrower->is_active) {
            $borrower->notify(new ToolLoanNotification($loan->load('tool'), 'lent'));
        }

        return (new ToolResource($tool->refresh()->load(self::full())))->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/tools/{tool}/loans/{loan} — date de retour, projet, notes (les rappels repartent de zéro si la date change). */
    public function updateLoan(Request $request, Tool $tool, ToolLoan $loan): ToolResource
    {
        $this->authorize('lend', $tool);
        abort_unless($loan->tool_id === $tool->id, 404);
        $data = $request->validate([
            'due_on' => ['sometimes', 'nullable', 'date'],
            'project_id' => ['sometimes', 'nullable', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'borrower_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $loan->fill($data);
        if ($loan->isDirty('due_on')) {
            $loan->forceFill(['reminder_level' => 0, 'last_reminded_on' => null]);
        }
        $loan->save();

        return new ToolResource($tool->refresh()->load(self::full()));
    }

    /** POST /api/v1/tools/{tool}/loans/{loan}/return — retour de l'outil, avec son état constaté. */
    public function returnTool(Request $request, Tool $tool, ToolLoan $loan): ToolResource
    {
        $this->authorize('lend', $tool);
        abort_unless($loan->tool_id === $tool->id, 404);
        abort_if($loan->returned_at !== null, 422, 'Ce prêt est déjà rendu.');
        $data = $request->validate([
            'returned_at' => ['nullable', 'date'],
            'condition' => ['nullable', Rule::in(array_keys(Tools::CONDITIONS))],
            'return_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $returnedAt = isset($data['returned_at']) ? \Illuminate\Support\Carbon::parse($data['returned_at']) : now();
        abort_if($returnedAt->lt($loan->lent_at), 422, 'La date de retour ne peut pas être avant la date du prêt.');

        DB::transaction(function () use ($loan, $tool, $data, $returnedAt, $request) {
            $loan->forceFill([
                'returned_at' => $returnedAt,
                'return_condition' => $data['condition'] ?? null,
                'return_notes' => $data['return_notes'] ?? null,
                'returned_by' => $request->user()->id,
            ])->save();
            if (! empty($data['condition'])) {
                $tool->update(['condition' => $data['condition']]);
            }
        });

        // Prévient celui qui a prêté, s'il n'enregistre pas lui-même le retour
        $lender = $loan->lender;
        if ($lender && $lender->id !== $request->user()->id && $lender->is_active) {
            $lender->notify(new ToolLoanNotification($loan->load('tool'), 'returned'));
        }

        return new ToolResource($tool->refresh()->load(self::full()));
    }

    /** POST /api/v1/tools/{tool}/loans/{loan}/remind — rappel envoyé tout de suite à l'emprunteur (utilisateur de Dash4). */
    public function remind(Request $request, Tool $tool, ToolLoan $loan): ToolResource
    {
        $this->authorize('lend', $tool);
        abort_unless($loan->tool_id === $tool->id, 404);
        abort_if($loan->returned_at !== null, 422, 'Ce prêt est déjà rendu.');
        $borrower = $loan->borrower;
        abort_if($borrower === null || ! $borrower->is_active, 422,
            "L'emprunteur n'a pas de compte Dash4 : contactez-le directement".($loan->borrower_phone ? " ({$loan->borrower_phone})" : '').'.');
        $borrower->notify(new ToolLoanNotification($loan->load('tool'), Tools::loanState($loan) === 'late' ? 'late' : 'reminder'));
        $loan->forceFill(['last_reminded_on' => today()])->save();

        return new ToolResource($tool->refresh()->load(self::full()));
    }

    /** DELETE /api/v1/tools/{tool}/loans/{loan} — prêt saisi par erreur. */
    public function destroyLoan(Tool $tool, ToolLoan $loan): ToolResource
    {
        $this->authorize('lend', $tool);
        abort_unless($loan->tool_id === $tool->id, 404);
        $loan->delete();

        return new ToolResource($tool->refresh()->load(self::full()));
    }

    private function validated(Request $request, ?Tool $tool): array
    {
        $sometimes = $tool ? 'sometimes' : 'nullable';

        $data = $request->validate([
            'article_id' => [$sometimes, 'nullable', Rule::exists('articles', 'id')->whereNull('deleted_at')],
            'name' => [$tool ? 'sometimes' : 'nullable', $tool ? 'required' : 'nullable', 'string', 'max:255'],
            'code' => [$sometimes, 'nullable', 'string', 'max:50', Rule::unique('tools', 'code')->ignore($tool?->id)],
            'serial_number' => [$sometimes, 'nullable', 'string', 'max:100'],
            'condition' => [$sometimes, Rule::in(array_keys(Tools::CONDITIONS))],
            'storage_location_id' => [$sometimes, 'nullable', Rule::exists('storage_locations', 'id')->whereNull('deleted_at')],
            'acquired_on' => [$sometimes, 'nullable', 'date'],
            'purchase_value' => [$sometimes, 'nullable', 'integer', 'min:0'],
            'notes' => [$sometimes, 'nullable', 'string', 'max:10000'],
        ], ['code.unique' => 'Ce numéro est déjà utilisé par un autre outil.']);
        if (array_key_exists('condition', $data) && $data['condition'] === null) {
            unset($data['condition']);
        }

        return $data;
    }
}
