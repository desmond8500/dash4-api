<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProjectForm;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    private const RELATIONS = ['client:id,name', 'manager:id,firstname,lastname'];

    private const COUNTS = ['notes', 'links', 'documents'];

    /**
     * GET /api/v1/projects
     * Filtres : search, status (liste séparée par des virgules), active=1 (à venir, en cours, en pause),
     * client_id, manager=me, favorite=1 ; include=notes,links (synchro du mobile) ; updated_since.
     * Tri : favoris, puis les plus récemment modifiés.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);
        $user = $request->user();

        $include = array_intersect(explode(',', (string) $request->input('include')), ['notes', 'links']);
        $with = [...self::RELATIONS, ...array_map(fn ($r) => "{$r}.creator:id,firstname,lastname", $include)];

        $query = Project::query()
            ->visibleTo($user)
            ->with($with)
            ->withCount(self::COUNTS)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('address', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->boolean('active'), fn ($q) => $q->whereIn('status', ProjectStatus::active()))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->input('manager') === 'me', fn ($q) => $q->where('manager_id', $user->id))
            ->when($request->boolean('favorite'), fn ($q) => $q->where('is_favorite', true))
            ->orderByDesc('is_favorite')
            ->latest('updated_at')
            ->orderByDesc('id');

        SyncQuery::apply($query, $request);

        return ProjectResource::collection($query->paginate(SyncQuery::perPage($request)))
            ->additional(SyncQuery::meta());
    }

    /** POST /api/v1/projects — renvoie le projet existant si ce client_uuid a déjà été reçu. */
    public function store(ProjectForm $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        if ($request->filled('client_uuid')
            && $existing = Project::withTrashed()->where('client_uuid', $request->input('client_uuid'))->first()) {
            return (new ProjectResource($this->full($existing)))->response()->setStatusCode(200);
        }

        $project = new Project($request->validated());
        $project->created_by = $request->user()->id;
        // Un chef de projet qui crée un projet en devient le responsable, sauf choix contraire
        if (! $request->has('manager_id') && $request->user()->hasRole('project_manager')) {
            $project->manager_id = $request->user()->id;
        }
        $project->save();

        return (new ProjectResource($this->full($project)))->response()->setStatusCode(201);
    }

    /** GET /api/v1/projects/{id} — avec notes, liens et documents. */
    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($this->full($project));
    }

    public function update(ProjectForm $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($this->full($project->refresh()));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(['message' => 'Projet supprimé.']);
    }

    private function full(Project $project): Project
    {
        return $project->load([
            ...self::RELATIONS,
            'notes.creator:id,firstname,lastname',
            'links.creator:id,firstname,lastname',
            'documents.creator:id,firstname,lastname',
        ])->loadCount(self::COUNTS);
    }
}
