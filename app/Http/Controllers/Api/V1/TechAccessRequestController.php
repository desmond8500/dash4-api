<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessRequestStatus;
use App\Enums\TechShareCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechAccessRequestResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TechAccessRequest;
use App\Models\TechShare;
use App\Models\User;
use App\Notifications\AccessRequestNotification;
use App\Notifications\TechShareNotification;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Demandes d'accès des techniciens (code, clé, badge, document…). Le chef de projet du projet
 * (ou un manager) accepte ou refuse ; accepter peut envoyer l'information au technicien, avec une expiration.
 */
class TechAccessRequestController extends Controller
{
    public const FULL = ['user:id,firstname,lastname', 'project:id,name,manager_id', 'task:id,title', 'decider:id,firstname,lastname'];

    /** GET /api/v1/tech-access-requests — filtres : status, pending=1, mine=1, project_id ; synchro : updated_since. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TechAccessRequest::class);
        $user = $request->user();
        $query = TechAccessRequest::query()->visibleTo($user)->with(self::FULL)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('pending'), fn ($q) => $q->where('status', AccessRequestStatus::Pending->value))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()->orderByDesc('id');
        SyncQuery::apply($query, $request);

        return TechAccessRequestResource::collection($query->paginate(SyncQuery::perPage($request, 50)))->additional(SyncQuery::meta());
    }

    /** POST /api/v1/tech-access-requests — `subject`, `reason`, `task_id` ou `project_id`, `needed_on`. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TechAccessRequest::class);
        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'subject' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'needed_on' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
        ], [], ['subject' => 'objet', 'reason' => 'motif', 'needed_on' => 'date souhaitée', 'project_id' => 'projet', 'task_id' => 'tâche']);
        if (! empty($data['client_uuid']) && $existing = TechAccessRequest::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new TechAccessRequestResource($existing->load(self::FULL)))->response()->setStatusCode(200);
        }
        $user = $request->user();
        if (! empty($data['task_id'])) {
            $task = Task::find($data['task_id']);
            abort_unless($user->can('view', $task), 403, "Vous n'avez pas accès à cette tâche.");
            $data['project_id'] = $task->project_id ?? ($data['project_id'] ?? null);
        } elseif (! empty($data['project_id'])) {
            // Un technicien ne voit pas les projets : il faut qu'il y ait une tâche
            $allowed = $user->can('view', Project::find($data['project_id']))
                || Task::query()->visibleTo($user)->where('project_id', $data['project_id'])->exists();
            abort_unless($allowed, 403, "Vous n'avez pas accès à ce projet.");
        }

        $accessRequest = new TechAccessRequest($data);
        $accessRequest->user_id = $user->id;
        $accessRequest->save();
        $accessRequest->load(self::FULL);

        $this->deciders($accessRequest)->each->notify(new AccessRequestNotification($accessRequest, 'created'));

        return (new TechAccessRequestResource($accessRequest))->response()->setStatusCode(201);
    }

    public function show(TechAccessRequest $techAccessRequest): TechAccessRequestResource
    {
        $this->authorize('view', $techAccessRequest);

        return new TechAccessRequestResource($techAccessRequest->load(self::FULL));
    }

    /**
     * POST /api/v1/tech-access-requests/{id}/decide — `decision` (approved | rejected), `response`.
     * Accepté avec `share` {title, content, category, expires_at} : l'information est envoyée au demandeur.
     */
    public function decide(Request $request, TechAccessRequest $techAccessRequest): TechAccessRequestResource
    {
        abort_unless($techAccessRequest->isPending(), 422, 'Cette demande a déjà reçu une réponse.');
        $this->authorize('decide', $techAccessRequest);
        $data = $request->validate([
            'decision' => ['required', Rule::in([AccessRequestStatus::Approved->value, AccessRequestStatus::Rejected->value])],
            'response' => ['nullable', 'string', 'max:5000', 'required_if:decision,rejected'],
            'share' => ['sometimes', 'nullable', 'array'],
            'share.title' => ['nullable', 'string', 'max:255'],
            'share.content' => ['required_with:share', 'nullable', 'string', 'max:10000'],
            'share.category' => ['nullable', new Enum(TechShareCategory::class)],
            'share.expires_at' => ['required_with:share', 'nullable', 'date', 'after:now', 'before:+1 year'],
        ], [
            'response.required_if' => 'Indiquez la raison du refus.',
            'share.content.required_with' => "Indiquez l'information à envoyer.",
            'share.expires_at.required_with' => "Indiquez jusqu'à quand l'information reste visible.",
            'share.expires_at.after' => "La date d'expiration doit être dans le futur.",
        ], ['decision' => 'décision', 'response' => 'réponse', 'share.content' => 'information', 'share.expires_at' => 'expiration']);
        $user = $request->user();
        $approved = $data['decision'] === AccessRequestStatus::Approved->value;

        $share = DB::transaction(function () use ($techAccessRequest, $data, $user, $approved) {
            $share = null;
            if ($approved && ! empty($data['share'])) {
                $share = new TechShare([
                    'category' => $data['share']['category'] ?? TechShareCategory::Access->value,
                    'title' => $data['share']['title'] ?? $techAccessRequest->subject,
                    'content' => $data['share']['content'],
                    'project_id' => $techAccessRequest->project_id,
                    'task_id' => $techAccessRequest->task_id,
                    'expires_at' => $data['share']['expires_at'],
                ]);
                $share->created_by = $user->id;
                $share->save();
                $share->recipients()->sync([$techAccessRequest->user_id]);
            }
            $techAccessRequest->forceFill([
                'status' => $data['decision'],
                'response' => $data['response'] ?? null,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'tech_share_id' => $share?->id,
            ])->save();

            return $share;
        });

        $techAccessRequest->load(self::FULL);
        $requester = $techAccessRequest->user;
        if ($share) {
            $requester?->notify(new TechShareNotification($share, $user->full_name));
        }
        $requester?->notify(new AccessRequestNotification($techAccessRequest, 'decided'));

        return new TechAccessRequestResource($techAccessRequest);
    }

    public function destroy(TechAccessRequest $techAccessRequest): JsonResponse
    {
        $this->authorize('delete', $techAccessRequest);
        $techAccessRequest->delete();

        return response()->json(['message' => 'Demande supprimée.']);
    }

    /** Qui est prévenu : le chef de projet du projet s'il décide, sinon ceux qui décident pour tous les projets. */
    private function deciders(TechAccessRequest $request)
    {
        $manager = $request->project?->manager_id ? User::query()->where('is_active', true)->find($request->project->manager_id) : null;
        if ($manager && $manager->id !== $request->user_id && $manager->can('tech_access_requests.decide')) {
            return collect([$manager]);
        }

        return User::permission('tech_access_requests.decide')->permission('tech_access_requests.view')
            ->where('is_active', true)->whereKeyNot($request->user_id)->get();
    }
}
