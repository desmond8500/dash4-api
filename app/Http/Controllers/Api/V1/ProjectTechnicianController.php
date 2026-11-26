<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Notifications\SiteAssignedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Techniciens affectés à un projet : ils voient sa partie chantier (bâtiments, plans, systèmes, équipements,
 * PV, DOE), jamais la fiche du projet, ses devis, notes, documents ni prix.
 */
class ProjectTechnicianController extends Controller
{
    /** GET /api/v1/projects/{id}/technicians — qui voit le projet. */
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->technicians()->get()->map(fn (User $u) => $this->person($u))]);
    }

    /**
     * PUT /api/v1/projects/{id}/technicians — `user_ids` : la liste complète des techniciens affectés
     * (comptes technicien actifs). Ceux qui arrivent sont prévenus.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $ids = $request->validate([
            'user_ids' => ['present', 'array', 'max:100'],
            'user_ids.*' => ['integer', 'distinct'],
        ], [], ['user_ids' => 'techniciens'])['user_ids'];

        $technicians = User::query()->whereIn('id', $ids)->where('account_type', AccountType::Technician)->where('is_active', true)->pluck('id');
        abort_if($technicians->count() !== count($ids), 422, 'Seuls des comptes technicien actifs peuvent être affectés à un chantier.');

        $before = $project->technicians()->pluck('users.id');
        $project->technicians()->syncWithPivotValues($technicians->all(), ['created_by' => $request->user()->id], true);
        $project->touch();

        $added = $technicians->diff($before);
        User::query()->whereIn('id', $added)->get()
            ->each(fn (User $u) => $u->notify(new SiteAssignedNotification($project, $request->user()->full_name)));

        return $this->index($project->refresh());
    }

    /**
     * GET /api/v1/site-projects — chantiers que l'utilisateur peut ouvrir (mobile : « Mes chantiers ») :
     * nom, client, adresse, nombre de plans, et si l'utilisateur y est affecté. Aucune autre donnée du projet.
     */
    public function sites(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['sites.view', 'sites.view.own']), 403, "Vous n'avez pas le droit de voir les chantiers.");

        $projects = Project::query()->siteVisibleTo($user)
            ->with('client:id,name')
            ->withCount('plans')
            ->withExists(['technicians as assigned' => fn ($q) => $q->whereKey($user->id)])
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get();

        return response()->json(['data' => $projects->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'client' => $p->client?->name,
            'address' => $p->address,
            'status' => ['value' => $p->status->value, 'label' => $p->status->label()],
            'plans_count' => $p->plans_count,
            'assigned' => (bool) $p->assigned,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ])]);
    }

    /** @return array{id: int, full_name: string, job_title: ?string} */
    private function person(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'job_title' => $user->job_title,
        ];
    }
}
