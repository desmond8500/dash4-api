<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Article;
use App\Models\Equipment;
use App\Models\InstalledSystem;
use App\Models\Project;
use App\Models\SystemProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Systèmes installés d'un chantier, leurs relevés d'avancement et leurs équipements.
 * Chaque modification renvoie le chantier complet (`SiteResource`).
 */
class InstalledSystemController extends Controller
{
    public function store(Request $request, Project $project): SiteResource
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
        $data = $this->systemData($request, $project, true);
        $data['name'] ??= \App\Models\System::find($data['system_id'] ?? 0)?->name ?? 'Système';
        $project->installedSystems()->create($data + ['position' => $project->installedSystems()->max('position') + 1]);

        return $this->site($project);
    }

    public function update(Request $request, InstalledSystem $installedSystem): SiteResource
    {
        $project = $installedSystem->project;
        $this->authorize('manageSite', $project);
        $installedSystem->update($this->systemData($request, $project, false));

        return $this->site($project);
    }

    public function destroy(InstalledSystem $installedSystem): SiteResource
    {
        $project = $installedSystem->project;
        $this->authorize('deleteSite', $project);
        $installedSystem->delete();

        return $this->site($project);
    }

    /**
     * POST /api/v1/installed-systems/{id}/progress — `percent` (0 à 100), `date`, `note`, `client_uuid`.
     * L'avancement du système est celui du relevé le plus récent.
     */
    public function storeProgress(Request $request, InstalledSystem $installedSystem): JsonResponse
    {
        $project = $installedSystem->project;
        $this->authorize('manageSite', $project);
        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'percent' => ['required', 'integer', 'between:0,100'],
            'date' => ['sometimes', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['date.before_or_equal' => "La date de l'avancement ne peut pas être dans le futur."], ['percent' => 'avancement', 'date' => 'date']);

        $exists = ! empty($data['client_uuid']) && SystemProgress::query()->where('client_uuid', $data['client_uuid'])->exists();
        if (! $exists) {
            $entry = $installedSystem->progressEntries()->make($data + ['date' => today()]);
            $entry->created_by = $request->user()->id;
            $entry->save();
            $this->recompute($installedSystem);
        }

        return $this->site($project)->response()->setStatusCode($exists ? 200 : 201);
    }

    public function destroyProgress(SystemProgress $progress): SiteResource
    {
        $system = $progress->installedSystem;
        $this->authorize('manageSite', $system->project);
        $progress->delete();
        $this->recompute($system);

        return $this->site($system->project);
    }

    /** POST /api/v1/installed-systems/{id}/equipment */
    public function storeEquipment(Request $request, InstalledSystem $installedSystem): JsonResponse
    {
        $project = $installedSystem->project;
        $this->authorize('manageSite', $project);
        $data = $this->equipmentData($request, $installedSystem, true);
        if (! empty($data['client_uuid']) && Equipment::withTrashed()->where('client_uuid', $data['client_uuid'])->exists()) {
            return $this->site($project)->response();
        }
        $data['name'] ??= Article::find($data['article_id'] ?? 0)?->designation ?? 'Équipement';
        unset($data['installed_system_id']);
        $installedSystem->equipment()->create($data + ['position' => $installedSystem->equipment()->max('position') + 1]);

        return $this->site($project)->response()->setStatusCode(201);
    }

    public function updateEquipment(Request $request, Equipment $equipment): SiteResource
    {
        $system = $equipment->installedSystem;
        $this->authorize('manageSite', $system->project);
        $data = $this->equipmentData($request, $system, false, $equipment);
        // Changer de système : l'équipement et ses sous-équipements suivent
        DB::transaction(function () use ($equipment, $data) {
            $equipment->update(array_diff_key($data, ['installed_system_id' => true, 'client_uuid' => true]));
            if (array_key_exists('installed_system_id', $data)) {
                $equipment->forceFill(['installed_system_id' => $data['installed_system_id']])->save();
                $equipment->children()->update(['installed_system_id' => $data['installed_system_id']]);
            }
        });

        return $this->site($system->project);
    }

    /**
     * POST /api/v1/equipment/{id}/split — éclate un groupe (quantité N) en N appareils de quantité 1,
     * avec les mêmes informations (article, type, modèle, emplacement, état, caractéristiques, notes, ligne du devis).
     * Le n° de série reste sur le premier ; les sous-équipements et les pièces jointes restent sur le premier.
     * Rejouer l'action sur un appareil déjà à 1 ne change rien.
     */
    public function splitEquipment(Equipment $equipment): SiteResource
    {
        $system = $equipment->installedSystem;
        $this->authorize('manageSite', $system->project);
        $copies = $equipment->quantity - 1;
        abort_if($copies > 499, 422, 'Plus de 500 unités : éclatez un groupe plus petit.');
        if ($copies > 0) {
            DB::transaction(function () use ($equipment, $system, $copies) {
                // Les copies se placent juste après l'original
                $system->equipment()->where('position', '>', $equipment->position)->increment('position', $copies);
                $fields = $equipment->only(['parent_id', 'article_id', 'quote_line_id', 'level_id', 'room_id', 'name', 'type', 'model', 'status', 'characteristics', 'description']);
                for ($i = 1; $i <= $copies; $i++) {
                    $system->equipment()->create($fields + ['quantity' => 1, 'serial_number' => null, 'position' => $equipment->position + $i]);
                }
                $equipment->update(['quantity' => 1]);
            });
        }

        return $this->site($system->project);
    }

    public function destroyEquipment(Equipment $equipment): SiteResource
    {
        $project = $equipment->installedSystem->project;
        $this->authorize('deleteSite', $project);
        DB::transaction(function () use ($equipment) {
            $equipment->children()->get()->each->delete();
            $equipment->delete();
        });

        return $this->site($project);
    }

    private function recompute(InstalledSystem $system): void
    {
        $latest = $system->progressEntries()->first();
        $system->forceFill(['progress' => $latest?->percent ?? 0])->save();
    }

    private function site(Project $project): SiteResource
    {
        return (new SiteController)->tree($project->refresh());
    }

    private function systemData(Request $request, Project $project, bool $creating): array
    {
        return $request->validate([
            'system_id' => ['sometimes', 'nullable', 'integer', Rule::exists('systems', 'id')->whereNull('deleted_at')],
            'building_id' => ['sometimes', 'nullable', 'integer', Rule::exists('buildings', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'name' => [$creating ? 'required_without:system_id' : 'sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ], ['name.required_without' => 'Choisissez un système du catalogue ou donnez un nom.', 'building_id.exists' => "Ce bâtiment n'est pas sur ce projet."], ['name' => 'nom', 'building_id' => 'bâtiment']);
    }

    private function equipmentData(Request $request, InstalledSystem $system, bool $creating, ?Equipment $equipment = null): array
    {
        $buildingIds = $system->project->buildings()->pluck('id');
        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'installed_system_id' => ['sometimes', 'integer', Rule::exists('installed_systems', 'id')->where('project_id', $system->project_id)->whereNull('deleted_at')],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('equipment', 'id')->where('installed_system_id', $request->input('installed_system_id', $system->id))->whereNull('deleted_at')],
            'article_id' => ['sometimes', 'nullable', 'integer', Rule::exists('articles', 'id')],
            'level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('levels', 'id')->whereIn('building_id', $buildingIds)],
            'room_id' => ['sometimes', 'nullable', 'integer', Rule::exists('rooms', 'id')],
            'name' => [$creating ? 'required_without:article_id' : 'sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', new Enum(\App\Enums\EquipmentStatus::class)],
            'characteristics' => ['sometimes', 'nullable', 'array', 'max:40'],
            'characteristics.*.label' => ['required', 'string', 'max:100'],
            'characteristics.*.value' => ['nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ], [
            'name.required_without' => "Donnez un nom à l'équipement ou choisissez un article.",
            'level_id.exists' => "Ce niveau n'est pas sur ce projet.",
        ], ['name' => 'nom', 'quantity' => 'quantité', 'characteristics.*.label' => 'libellé de la caractéristique']);

        // La pièce doit être dans un niveau du projet (et dans le niveau choisi s'il y en a un)
        if (! empty($data['room_id'])) {
            $levelId = \App\Models\Room::query()->whereKey($data['room_id'])->value('level_id');
            $levelOk = \App\Models\Level::query()->whereKey($levelId)->whereIn('building_id', $buildingIds)->exists();
            abort_unless($levelOk, 422, "Cette pièce n'est pas sur ce projet.");
            $data['level_id'] = $levelId;
        }
        abort_if($equipment && ($data['parent_id'] ?? null) === $equipment->id, 422, 'Un équipement ne peut pas être son propre parent.');
        if (array_key_exists('characteristics', $data)) {
            $data['characteristics'] = array_values(array_map(fn ($c) => ['label' => trim($c['label']), 'value' => trim((string) ($c['value'] ?? ''))], $data['characteristics'] ?? []));
        }

        return $data;
    }
}
