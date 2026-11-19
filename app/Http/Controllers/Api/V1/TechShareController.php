<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TechShareCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechShareResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TechShare;
use App\Models\User;
use App\Notifications\TechShareNotification;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Informations temporaires envoyées aux techniciens (codes, matériel, consignes, fichiers).
 * Elles expirent à une date (heure du serveur) ou à la fin de la tâche : le contenu est alors effacé.
 */
class TechShareController extends Controller
{
    public const FULL = ['project:id,name,manager_id', 'task:id,title,status', 'recipients:id,firstname,lastname', 'creator:id,firstname,lastname', 'documents'];

    /**
     * GET /api/v1/tech-shares — filtres : status=active|expired, received=1, mine=1, project_id, task_id, search.
     * Un technicien ne voit que les informations en cours (sauf en synchro : les expirées reviennent sans contenu,
     * pour que le téléphone les efface).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TechShare::class);
        $user = $request->user();
        $status = $request->input('status', $user->isTechnician() && ! SyncQuery::isSync($request) ? 'active' : null);

        $query = TechShare::query()->visibleTo($user)->with(self::FULL)
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'expired', fn ($q) => $q->whereNotIn('id', TechShare::query()->active()->select('id')))
            ->when($request->boolean('received'), fn ($q) => $q->whereHas('recipients', fn ($r) => $r->whereKey($user->id)))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $user->id))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('task_id'), fn ($q) => $q->where('task_id', $request->integer('task_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->orderByRaw('CASE WHEN expired_at IS NULL THEN 0 ELSE 1 END')->latest()->orderByDesc('id');
        SyncQuery::apply($query, $request);

        return TechShareResource::collection($query->paginate(SyncQuery::perPage($request, 50)))->additional(SyncQuery::meta());
    }

    /** POST /api/v1/tech-shares — `recipients` (ids), `expires_at` ou `expires_with_task` avec `task_id`. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TechShare::class);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = TechShare::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new TechShareResource($existing->load(self::FULL)))->response()->setStatusCode(200);
        }
        $user = $request->user();
        $this->checkScope($user, $data);

        $share = DB::transaction(function () use ($data, $user) {
            $share = new TechShare(array_diff_key($data, ['recipients' => true]));
            $share->created_by = $user->id;
            $share->save();
            $share->recipients()->sync($data['recipients']);

            return $share;
        });
        $this->notify($share, $data['recipients'], $user);

        return (new TechShareResource($share->refresh()->load(self::FULL)))->response()->setStatusCode(201);
    }

    public function show(TechShare $techShare): TechShareResource
    {
        $this->authorize('view', $techShare);

        return new TechShareResource($techShare->load(self::FULL));
    }

    /** PATCH — `recipients` envoyés : remplacent la liste (les nouveaux sont prévenus). */
    public function update(Request $request, TechShare $techShare): TechShareResource
    {
        $this->authorize('update', $techShare);
        $data = array_diff_key($this->validated($request, false, $techShare), ['client_uuid' => true]);
        $user = $request->user();
        $this->checkScope($user, $data);

        $added = [];
        DB::transaction(function () use ($techShare, $data, &$added) {
            $techShare->update(array_diff_key($data, ['recipients' => true]));
            if (array_key_exists('recipients', $data)) {
                $added = $techShare->recipients()->sync($data['recipients'])['attached'];
            }
        });
        $this->notify($techShare, $added, $user);

        return new TechShareResource($techShare->refresh()->load(self::FULL));
    }

    public function destroy(TechShare $techShare): JsonResponse
    {
        $this->authorize('delete', $techShare);
        // Supprimée : le contenu et les fichiers sont effacés tout de suite
        if (! $techShare->expired_at) {
            $techShare->expire();
        }
        $techShare->delete();

        return response()->json(['message' => 'Information supprimée.']);
    }

    /** POST /api/v1/tech-shares/{id}/read — le destinataire indique l'avoir lue. */
    public function read(Request $request, TechShare $techShare): TechShareResource
    {
        $this->authorize('view', $techShare);
        $user = $request->user();
        abort_unless($techShare->recipients()->whereKey($user->id)->exists(), 422, "Vous n'êtes pas destinataire de cette information.");
        $techShare->recipients()->updateExistingPivot($user->id, ['read_at' => now()]);
        $techShare->touch();

        return new TechShareResource($techShare->refresh()->load(self::FULL));
    }

    /** POST /api/v1/tech-shares/{id}/expire — effacer tout de suite le contenu. */
    public function expire(TechShare $techShare): TechShareResource
    {
        $this->authorize('update', $techShare);
        $techShare->expire();

        return new TechShareResource($techShare->refresh()->load(self::FULL));
    }

    /** Sans tech_shares.view (chef de projet) : projet et tâche doivent être les siens ou lui être visibles. */
    private function checkScope(User $user, array &$data): void
    {
        if (! empty($data['task_id'])) {
            $task = Task::find($data['task_id']);
            abort_unless($user->can('view', $task), 403, "Vous n'avez pas accès à cette tâche.");
            $data['project_id'] ??= $task->project_id;
        }
        if (! empty($data['project_id']) && ! $user->can('tech_shares.view')) {
            $project = Project::find($data['project_id']);
            abort_unless($project?->manager_id === $user->id, 403, 'Vous ne pouvez envoyer des informations que pour vos projets.');
        }
    }

    private function notify(TechShare $share, array $userIds, User $by): void
    {
        $ids = array_values(array_diff($userIds, [$by->id]));
        if ($ids) {
            User::query()->whereKey($ids)->get()->each->notify(new TechShareNotification($share, $by->full_name));
        }
    }

    private function validated(Request $request, bool $creating, ?TechShare $share = null): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $withTask = $request->boolean('expires_with_task', (bool) $share?->expires_with_task);
        $taskId = $request->input('task_id', $share?->task_id);

        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'category' => ['sometimes', new Enum(TechShareCategory::class)],
            'title' => [$required, 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'task_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
            'expires_with_task' => ['sometimes', 'boolean'],
            'expires_at' => [
                ...($creating && ! $withTask ? ['required'] : ['sometimes']),
                ...($withTask ? ['nullable'] : []),
                'date', 'after:now', 'before:+1 year',
            ],
            'recipients' => [$required, 'array', 'min:1', 'max:50'],
            'recipients.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
        ], [
            'expires_at.required' => "Indiquez jusqu'à quand l'information reste visible.",
            'expires_at.after' => "La date d'expiration doit être dans le futur.",
            'expires_at.before' => "Une information temporaire dure au plus un an.",
            'recipients.required' => 'Choisissez au moins un destinataire.',
            'recipients.min' => 'Choisissez au moins un destinataire.',
        ], [
            'title' => 'titre', 'content' => 'contenu', 'expires_at' => 'expiration', 'recipients' => 'destinataires',
            'recipients.*' => 'destinataire', 'task_id' => 'tâche', 'project_id' => 'projet',
        ]);
        abort_if($withTask && ! $taskId, 422, "Choisissez la tâche à la fin de laquelle l'information expire.");

        return $data;
    }
}
