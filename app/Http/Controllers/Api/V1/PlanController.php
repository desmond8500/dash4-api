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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plans d'un chantier (image ou PDF, 30 Mo) et marqueurs d'équipements. Les plans DWG sont à
 * convertir en PDF avant l'envoi : l'hébergement n'a pas d'outil de conversion.
 */
class PlanController extends Controller
{
    /** Repères avec ce qu'il faut pour l'icône et le calque. */
    public const MARKERS = ['markers.equipment:id,installed_system_id,article_id', 'markers.equipment.article:id,plan_icon_id'];

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewSite', $project);

        return PlanResource::collection($project->plans()->with(self::MARKERS)->get());
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

        return (new PlanResource($plan->load(self::MARKERS)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $this->authorize('update', $plan);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'settings' => ['sometimes', 'array'],
            'settings.hidden_layers' => ['sometimes', 'array', 'max:200'],
            'settings.hidden_layers.*' => ['string', 'max:20'],
            'settings.legend' => ['sometimes', 'boolean'],
            'settings.labels' => ['sometimes', 'boolean'],
            'settings.legend_position' => ['sometimes', Rule::in(['below', 'right', 'overlay'])],
            'settings.legend_x' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.legend_y' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.legend_scale' => ['sometimes', 'numeric', 'between:0.5,2.5'],
            ...$this->placeRules($plan->project),
        ]);
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($plan->settings ?? [], $data['settings']);
        }
        $plan->update($data);

        return new PlanResource($plan->load(self::MARKERS));
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
            return (new PlanResource($plan->load(self::MARKERS)))->response();
        }
        $plan->markers()->create($data);

        return (new PlanResource($plan->refresh()->load(self::MARKERS)))->response()->setStatusCode(201);
    }

    public function updateMarker(Request $request, PlanMarker $marker): PlanResource
    {
        $plan = $marker->plan;
        $this->authorize('update', $plan);
        $data = array_diff_key($this->markerData($request, $plan, false), ['client_uuid' => true]);
        if (isset($data['rotation'])) {
            $data['rotation'] = (($data['rotation'] % 360) + 360) % 360;
        }
        $marker->update($data);

        return new PlanResource($plan->refresh()->load(self::MARKERS));
    }

    public function destroyMarker(PlanMarker $marker): PlanResource
    {
        $plan = $marker->plan;
        $this->authorize('update', $plan);
        $marker->delete();

        return new PlanResource($plan->refresh()->load(self::MARKERS));
    }

    /**
     * POST /api/v1/plans/{id}/render — aperçu d'une page fait par l'éditeur (plan, repères visibles, légende),
     * en PNG ou JPEG : c'est lui qui va dans le DOE et le PDF du plan (un plan PDF ne s'affiche pas tel quel dans un PDF).
     */
    public function storeRender(Request $request, Plan $plan): PlanResource
    {
        $this->authorize('update', $plan);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:15360', 'mimes:png,jpg,jpeg'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'width' => ['sometimes', 'integer', 'min:1', 'max:20000'],
            'height' => ['sometimes', 'integer', 'min:1', 'max:20000'],
        ], ['file.max' => "L'aperçu ne doit pas dépasser 15 Mo."], ['file' => 'aperçu']);
        $page = (string) ($data['page'] ?? 1);
        $renders = $plan->renders ?? [];
        $old = $renders[$page]['path'] ?? null;
        $path = $request->file('file')->store("plans/{$plan->project_id}/renders", Plan::DISK);
        $renders[$page] = ['path' => $path, 'width' => $data['width'] ?? null, 'height' => $data['height'] ?? null, 'updated_at' => now()->toIso8601String()];
        $plan->forceFill(['renders' => $renders])->saveQuietly();
        if ($old && $old !== $path) {
            Storage::disk(Plan::DISK)->delete($old);
        }

        return new PlanResource($plan->load(self::MARKERS));
    }

    public function render(Plan $plan, int $page): StreamedResponse
    {
        $this->authorize('view', $plan);
        $path = $plan->renderPath($page) ?? abort(404, "Pas encore d'aperçu pour cette page : ouvrez le plan dans l'éditeur.");

        return Storage::disk(Plan::DISK)->response($path, "{$plan->name} — page {$page}.".pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, max-age=300']);
    }

    /** GET /api/v1/plans/{id}/pdf — le plan avec ses repères, la légende et la liste des équipements. */
    public function pdf(Request $request, Plan $plan): Response
    {
        $this->authorize('view', $plan);
        $pdf = \App\Support\QuotePdf::plan($plan);
        $name = "Plan {$plan->name}.pdf";

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
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
            'plan_icon_id' => ['sometimes', 'nullable', 'integer', Rule::exists('plan_icons', 'id')->whereNull('deleted_at')],
            'installed_system_id' => ['sometimes', 'nullable', 'integer', Rule::in($systemIds->all())],
            'size' => ['sometimes', 'numeric', 'between:0.005,0.3'],
            'rotation' => ['sometimes', 'integer', 'between:-360,360'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'show_label' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        ], ['installed_system_id.in' => "Ce système n'est pas sur ce chantier.", 'equipment_id.exists' => "Cet équipement n'est pas sur ce chantier.", 'color.regex' => 'Couleur au format #rrggbb.'], ['x' => 'position', 'y' => 'position']);
    }
}
