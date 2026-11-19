<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Tâches et planning. Une personne affectée (technicien compris) fait avancer la tâche :
 * statut, sous-tâches, notes et photos ; le reste se modifie avec tasks.update(.own).
 */
class TaskController extends Controller
{
    public const FULL = ['project:id,name,address,latitude,longitude,manager_id', 'client:id,name,address', 'quote:id,number', 'assignees:id,firstname,lastname', 'subtasks', 'creator:id,firstname,lastname', 'completer:id,firstname,lastname'];

    /**
     * GET /api/v1/tasks — filtres : status (liste), open=1, assigned_to (id ou « me »), project_id, client_id,
     * quote_id, priority, overdue=1, from + to (créneau au planning qui chevauche la période), unscheduled=1,
     * search ; synchro : updated_since.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);
        $user = $request->user();
        $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']], [], ['from' => 'début', 'to' => 'fin']);

        $query = Task::query()->visibleTo($user)->with(self::FULL)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('open'), fn ($q) => $q->whereIn('status', TaskStatus::open()))
            ->when($request->filled('assigned_to'), function ($q) use ($request, $user) {
                $id = $request->input('assigned_to') === 'me' ? $user->id : $request->integer('assigned_to');
                $q->whereHas('assignees', fn ($a) => $a->whereKey($id));
            })
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('quote_id'), fn ($q) => $q->where('quote_id', $request->integer('quote_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', TaskStatus::open())->whereDate('due_date', '<', today()))
            ->when($request->filled('from') && $request->filled('to'), fn ($q) => $q->scheduledBetween((string) $request->string('from'), (string) $request->string('to')))
            ->when($request->boolean('unscheduled'), fn ($q) => $q->whereNull('starts_at'))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->orderByRaw("CASE WHEN status IN ('done', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw(Priority::orderSql())
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')
            ->orderBy('starts_at')->orderByDesc('id');
        SyncQuery::apply($query, $request);

        return TaskResource::collection($query->paginate(SyncQuery::perPage($request, 100)))->additional(SyncQuery::meta());
    }

    /** GET /api/v1/tasks/summary — mes tâches ouvertes, aujourd'hui, en retard ; à affecter. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);
        $user = $request->user();
        $mine = fn () => Task::query()->whereIn('status', TaskStatus::open())->whereHas('assignees', fn ($a) => $a->whereKey($user->id));

        return response()->json(['data' => [
            'mine_open' => $mine()->count(),
            'mine_today' => $mine()->scheduledBetween(today()->toDateString(), today()->toDateString())->count(),
            'mine_overdue' => $mine()->whereDate('due_date', '<', today())->count(),
            'overdue' => Task::query()->visibleTo($user)->whereIn('status', TaskStatus::open())->whereDate('due_date', '<', today())->count(),
            'unassigned' => $user->can('tasks.assign') ? Task::query()->visibleTo($user)->whereIn('status', TaskStatus::open())->whereDoesntHave('assignees')->count() : 0,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = Task::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new TaskResource($existing->load(self::FULL)))->response()->setStatusCode(200);
        }
        $user = $request->user();
        abort_if(! empty($data['assignee_ids']) && ! $user->can('tasks.assign'), 403, "Vous n'avez pas le droit d'affecter une tâche.");

        $task = DB::transaction(function () use ($data, $user) {
            $task = new Task(array_diff_key($data, ['assignee_ids' => true, 'subtasks' => true, 'status' => true]));
            $task->created_by = $user->id;
            $task->save();
            $task->assignees()->sync($data['assignee_ids'] ?? []);
            foreach (array_values($data['subtasks'] ?? []) as $i => $title) {
                $task->subtasks()->create(['title' => $title, 'position' => $i]);
            }

            return $task;
        });
        $this->notifyAssigned($task, $data['assignee_ids'] ?? [], $user);

        return (new TaskResource($task->refresh()->load(self::FULL)))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load(self::FULL));
    }

    /**
     * PATCH /api/v1/tasks/{id} — `status` seul : qui fait avancer la tâche (personnes affectées) ;
     * le reste : tasks.update(.own) ; `assignee_ids` : tasks.assign.
     */
    public function update(Request $request, Task $task): TaskResource
    {
        $task->load('assignees', 'project');
        $data = array_diff_key($this->validated($request, false), ['client_uuid' => true, 'subtasks' => true]);
        $user = $request->user();

        $this->authorize(array_diff(array_keys($data), ['status']) === [] ? 'progress' : 'update', $task);
        if (array_key_exists('assignee_ids', $data)) {
            $this->authorize('assign', $task);
        }

        $before = $task->assignees->pluck('id')->all();
        DB::transaction(function () use ($task, $data, $user) {
            $task->fill(array_diff_key($data, ['assignee_ids' => true, 'status' => true]));
            if (isset($data['status'])) {
                $this->applyStatus($task, TaskStatus::from($data['status']), $user);
            }
            $task->save();
            if (array_key_exists('assignee_ids', $data)) {
                $task->assignees()->sync($data['assignee_ids']);
            }
        });
        if (array_key_exists('assignee_ids', $data)) {
            $this->notifyAssigned($task, array_diff($data['assignee_ids'], $before), $user);
        }

        return new TaskResource($task->refresh()->load(self::FULL));
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);
        $task->delete();

        return response()->json(['message' => 'Tâche supprimée.']);
    }

    /** POST /api/v1/tasks/{id}/subtasks — `title`. */
    public function addSubtask(Request $request, Task $task): TaskResource
    {
        $this->authorize('progress', $task->load('assignees'));
        $title = $request->validate(['title' => ['required', 'string', 'max:255']], [], ['title' => 'sous-tâche'])['title'];
        $task->subtasks()->create(['title' => $title, 'position' => (int) $task->subtasks()->max('position') + 1]);

        return new TaskResource($task->refresh()->load(self::FULL));
    }

    /** PATCH /api/v1/subtasks/{id} — `is_done`, `title`. */
    public function updateSubtask(Request $request, Subtask $subtask): TaskResource
    {
        $task = Task::with('assignees')->findOrFail($subtask->task_id);
        $this->authorize('progress', $task);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'is_done' => ['sometimes', 'boolean']], [], ['title' => 'sous-tâche']);
        $subtask->fill(array_diff_key($data, ['is_done' => true]));
        if (array_key_exists('is_done', $data) && $data['is_done'] !== $subtask->is_done) {
            $subtask->forceFill(['is_done' => $data['is_done'], 'done_at' => $data['is_done'] ? now() : null, 'done_by' => $data['is_done'] ? $request->user()->id : null]);
            // Commencer une sous-tâche met la tâche « En cours »
            if ($data['is_done'] && $task->status === TaskStatus::Todo) {
                $task->forceFill(['status' => TaskStatus::InProgress])->save();
            }
        }
        $subtask->save();

        return new TaskResource($task->refresh()->load(self::FULL));
    }

    public function removeSubtask(Subtask $subtask): TaskResource
    {
        $task = Task::with('assignees')->findOrFail($subtask->task_id);
        $this->authorize('progress', $task);
        $subtask->delete();

        return new TaskResource($task->refresh()->load(self::FULL));
    }

    private function applyStatus(Task $task, TaskStatus $status, User $user): void
    {
        $task->status = $status;
        $task->forceFill($status === TaskStatus::Done
            ? ['completed_at' => $task->completed_at ?? now(), 'completed_by' => $task->completed_by ?? $user->id]
            : ['completed_at' => null, 'completed_by' => null]);
    }

    private function notifyAssigned(Task $task, array $userIds, User $by): void
    {
        $task->loadMissing('project');
        User::query()->whereIn('id', $userIds)->whereKeyNot($by->id)->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new TaskAssignedNotification($task, $by->full_name)));
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', new Enum(TaskStatus::class)],
            'priority' => ['sometimes', new Enum(Priority::class)],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'quote_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quotes', 'id')->whereNull('deleted_at')],
            'client_request_id' => ['sometimes', 'nullable', 'integer', Rule::exists('client_requests', 'id')],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', ...($request->filled('starts_at') ? ['after_or_equal:starts_at'] : [])],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assignee_ids' => ['sometimes', 'array', 'max:50'],
            'assignee_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
            'subtasks' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:100'],
            'subtasks.*' => ['string', 'max:255'],
        ], ['ends_at.after_or_equal' => 'La fin doit être après le début.'], [
            'title' => 'titre', 'status' => 'statut', 'priority' => 'priorité', 'project_id' => 'projet', 'client_id' => 'client',
            'quote_id' => 'devis', 'location' => 'lieu', 'starts_at' => 'début', 'ends_at' => 'fin', 'due_date' => 'échéance',
            'assignee_ids' => 'personnes affectées',
        ]);
    }
}
