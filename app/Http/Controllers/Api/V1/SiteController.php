<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Building;
use App\Models\Level;
use App\Models\Project;
use App\Models\Room;
use App\Models\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chantier d'un projet (`sites.*`) : arbre complet (bâtiments → niveaux → pièces, systèmes installés et
 * équipements) et gestion des bâtiments, niveaux et pièces ; catalogue des systèmes.
 */
class SiteController extends Controller
{
    public const TREE = [
        'buildings.levels.rooms',
        'installedSystems.system',
        'installedSystems.equipment.article:id,designation,reference',
        'installedSystems.progressEntries.creator:id,firstname,lastname',
    ];

    /** GET /api/v1/projects/{project}/site — tout le chantier, pour l'écran et la copie hors ligne du téléphone. */
    public function show(Project $project): SiteResource
    {
        $this->authorize('viewSite', $project);

        return $this->tree($project);
    }

    public function tree(Project $project): SiteResource
    {
        return new SiteResource($project->load(self::TREE)->loadCount('receptionReports'));
    }

    // --- Bâtiments, niveaux, pièces --------------------------------------------------------

    public function storeBuilding(Request $request, Project $project): SiteResource
    {
        $this->authorizeCreate($request, $project);
        $project->buildings()->create($this->place($request) + ['position' => $project->buildings()->max('position') + 1]);

        return $this->tree($project);
    }

    public function updateBuilding(Request $request, Building $building): SiteResource
    {
        $this->authorize('manageSite', $building->project);
        $building->update($this->place($request, false));

        return $this->tree($building->project);
    }

    public function destroyBuilding(Building $building): SiteResource
    {
        $this->authorize('deleteSite', $building->project);
        $building->delete();

        return $this->tree($building->project);
    }

    public function storeLevel(Request $request, Building $building): SiteResource
    {
        $this->authorizeCreate($request, $building->project);
        $building->levels()->create($this->place($request) + ['position' => $building->levels()->max('position') + 1]);

        return $this->tree($building->project);
    }

    public function updateLevel(Request $request, Level $level): SiteResource
    {
        $project = $level->building->project;
        $this->authorize('manageSite', $project);
        $level->update($this->place($request, false));

        return $this->tree($project);
    }

    public function destroyLevel(Level $level): SiteResource
    {
        $project = $level->building->project;
        $this->authorize('deleteSite', $project);
        $level->delete();

        return $this->tree($project);
    }

    public function storeRoom(Request $request, Level $level): SiteResource
    {
        $project = $level->building->project;
        $this->authorizeCreate($request, $project);
        $level->rooms()->create($this->place($request) + ['position' => $level->rooms()->max('position') + 1]);

        return $this->tree($project);
    }

    public function updateRoom(Request $request, Room $room): SiteResource
    {
        $project = $room->level->building->project;
        $this->authorize('manageSite', $project);
        $room->update($this->place($request, false));

        return $this->tree($project);
    }

    public function destroyRoom(Room $room): SiteResource
    {
        $project = $room->level->building->project;
        $this->authorize('deleteSite', $project);
        $room->delete();

        return $this->tree($project);
    }

    // --- Catalogue des systèmes ----------------------------------------------------------

    /** GET /api/v1/systems — pour qui voit un chantier. */
    public function systems(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['sites.view', 'sites.view.own']), 403, "Vous n'avez pas le droit de faire cette action.");

        return response()->json(['data' => System::query()->orderBy('name')->get(['id', 'name', 'icon', 'description'])]);
    }

    public function storeSystem(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $system = System::create($this->systemData($request, true));

        return response()->json(['data' => $system->only(['id', 'name', 'icon', 'description'])], 201);
    }

    public function updateSystem(Request $request, System $system): JsonResponse
    {
        abort_unless($request->user()->can('sites.update'), 403, "Vous n'avez pas le droit de faire cette action.");
        $system->update($this->systemData($request, false));

        return response()->json(['data' => $system->only(['id', 'name', 'icon', 'description'])]);
    }

    public function destroySystem(Request $request, System $system): JsonResponse
    {
        abort_unless($request->user()->can('sites.delete'), 403, "Vous n'avez pas le droit de faire cette action.");
        $system->delete();

        return response()->json(['message' => 'Système retiré du catalogue.']);
    }

    private function authorizeCreate(Request $request, Project $project): void
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
    }

    private function place(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ], [], ['name' => 'nom', 'description' => 'description']);
    }

    private function systemData(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['name' => 'nom', 'icon' => 'icône']);
    }
}
