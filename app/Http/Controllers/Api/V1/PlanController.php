<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\PlanMarker;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plans d'un chantier (image ou PDF, 30 Mo) et marqueurs d'équipements. Les plans DWG sont à
 * convertir en PDF avant l'envoi : l'hébergement n'a pas d'outil de conversion.
 */
class PlanController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewSite', $project);

        return PlanResource::collection($project->plans()->with('markers')->get());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
        $file = $request->file('file');
        if ($file && in_array(strtolower($file->getClientOriginalExtension()), ['dwg', 'dxf'], true)) {
            abort(422, 'Les plans DWG ne sont pas lus ici : exportez-les en PDF (ou en image) avant de les envoyer.');
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:30720', 'mimes:pdf,png,jpg,jpeg,webp'],
            'name' => ['nullable', 'string', 'max:255'],
            ...$this->placeRules($project),
        ], ['file.mimes' => 'Le plan doit être un PDF ou une image (PNG, JPEG, WebP).', 'file.max' => 'Le plan ne doit pas dépasser 30 Mo.'], ['file' => 'plan']);

        $plan = $project->plans()->make([
            'name' => $data['name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'building_id' => $data['building_id'] ?? null,
            'level_id' => $data['level_id'] ?? null,
            'position' => $project->plans()->max('position') + 1,
        ]);
        $plan->path = $file->store("plans/{$project->id}", Plan::DISK);
        $plan->mime = $file->getMimeType() ?? 'application/octet-stream';
        $plan->kind = $plan->mime === 'application/pdf' ? 'pdf' : 'image';
        $plan->size = $file->getSize();
        $plan->created_by = $request->user()->id;
        $plan->save();

        return (new PlanResource($plan->load('markers')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $this->authorize('update', $plan);
        $plan->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            ...$this->placeRules($plan->project),
        ]));

        return new PlanResource($plan->load('markers'));
    }

    public function destroy(Plan $plan): JsonResponse
    {
        $this->authorize('delete', $plan);
        $plan->delete();

        return response()->json(['message' => 'Plan supprimé.']);
    }

    public function file(Plan $plan): StreamedResponse
    {
        $this->authorize('view', $plan);
        abort_unless(Storage::disk(Plan::DISK)->exists($plan->path), 404, 'Fichier introuvable.');
        $ext = $plan->kind === 'pdf' ? 'pdf' : pathinfo($plan->path, PATHINFO_EXTENSION);

        return Storage::disk(Plan::DISK)->response($plan->path, "{$plan->name}.{$ext}", ['Content-Type' => $plan->mime, 'Cache-Control' => 'private, max-age=3600']);
    }

    /** POST /api/v1/plans/{id}/markers — `x`, `y` (0 à 1), `page`, `equipment_id`, `label`, `color`, `client_uuid`. */
    public function storeMarker(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize('update', $plan);
        $data = $this->markerData($request, $plan, true);
        if (! empty($data['client_uuid']) && PlanMarker::query()->where('client_uuid', $data['client_uuid'])->exists()) {
            return (new PlanResource($plan->load('markers')))->response();
        }
        $plan->markers()->create($data);

        return (new PlanResource($plan->refresh()->load('markers')))->response()->setStatusCode(201);
    }

    public function updateMarker(Request $request, PlanMarker $marker): PlanResource
    {
        $plan = $marker->plan;
        $this->authorize('update', $plan);
        $marker->update(array_diff_key($this->markerData($request, $plan, false), ['client_uuid' => true]));

        return new PlanResource($plan->refresh()->load('markers'));
    }

    public function destroyMarker(PlanMarker $marker): PlanResource
    {
        $plan = $marker->plan;
        $this->authorize('update', $plan);
        $marker->delete();

        return new PlanResource($plan->refresh()->load('markers'));
    }

    private function placeRules(Project $project): array
    {
        return [
            'building_id' => ['sometimes', 'nullable', 'integer', Rule::exists('buildings', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('levels', 'id')->whereIn('building_id', $project->buildings()->pluck('id'))],
        ];
    }

    private function markerData(Request $request, Plan $plan, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $systemIds = $plan->project->installedSystems()->pluck('id');

        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'x' => [$required, 'numeric', 'between:0,1'],
            'y' => [$required, 'numeric', 'between:0,1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'equipment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('equipment', 'id')->whereIn('installed_system_id', $systemIds)->whereNull('deleted_at')],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        ], ['equipment_id.exists' => "Cet équipement n'est pas sur ce chantier.", 'color.regex' => 'Couleur au format #rrggbb.'], ['x' => 'position', 'y' => 'position']);
    }
}
