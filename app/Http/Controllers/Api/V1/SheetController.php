<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SheetResource;
use App\Models\Project;
use App\Models\Sheet;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/** Fiches techniques d'un chantier (zones d'alarme, lecteurs, détecteurs…) avec leur PDF. */
class SheetController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewSite', $project);

        return SheetResource::collection($project->sheets()->with('zones')->get());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
        $data = $this->validated($request, $project, true);
        $sheet = DB::transaction(function () use ($project, $data, $request) {
            $sheet = $project->sheets()->make(array_diff_key($data, ['zones' => true]));
            $sheet->created_by = $request->user()->id;
            $sheet->save();
            $this->writeZones($sheet, $data['zones'] ?? []);

            return $sheet;
        });

        return (new SheetResource($sheet->load('zones')))->response()->setStatusCode(201);
    }

    public function show(Sheet $sheet): SheetResource
    {
        $this->authorize('view', $sheet);

        return new SheetResource($sheet->load('zones'));
    }

    /** PATCH — `zones` envoyées : remplacent la liste. */
    public function update(Request $request, Sheet $sheet): SheetResource
    {
        $this->authorize('update', $sheet);
        $data = $this->validated($request, $sheet->project, false);
        DB::transaction(function () use ($sheet, $data) {
            $sheet->update(array_diff_key($data, ['zones' => true]));
            if (array_key_exists('zones', $data)) {
                $sheet->zones()->delete();
                $this->writeZones($sheet, $data['zones']);
            }
        });

        return new SheetResource($sheet->refresh()->load('zones'));
    }

    public function destroy(Sheet $sheet): JsonResponse
    {
        $this->authorize('delete', $sheet);
        $sheet->delete();

        return response()->json(['message' => 'Fiche supprimée.']);
    }

    public function pdf(Request $request, Sheet $sheet): Response
    {
        $this->authorize('view', $sheet);
        $pdf = QuotePdf::sheet($sheet);
        $name = PdfName::sheet($sheet->loadMissing('project.client'));

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    private function writeZones(Sheet $sheet, array $zones): void
    {
        foreach (array_values($zones) as $i => $z) {
            $sheet->zones()->create([
                'position' => $i,
                'number' => trim((string) $z['number']),
                'name' => trim((string) $z['name']),
                'code' => $z['code'] ?? null,
                'equipment_label' => $z['equipment_label'] ?? null,
                'equipment_id' => $z['equipment_id'] ?? null,
                'room_id' => $z['room_id'] ?? null,
            ]);
        }
    }

    private function validated(Request $request, Project $project, bool $creating): array
    {
        $systemIds = $project->installedSystems()->pluck('id');
        $levelIds = \App\Models\Level::query()->whereIn('building_id', $project->buildings()->pluck('id'))->pluck('id');

        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date' => ['sometimes', 'nullable', 'date'],
            'building_id' => ['sometimes', 'nullable', 'integer', Rule::exists('buildings', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'installed_system_id' => ['sometimes', 'nullable', 'integer', Rule::in($systemIds->all())],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'zones' => ['sometimes', 'array', 'max:500'],
            'zones.*.number' => ['required', 'string', 'max:50'],
            'zones.*.name' => ['required', 'string', 'max:255'],
            'zones.*.code' => ['nullable', 'string', 'max:100'],
            'zones.*.equipment_label' => ['nullable', 'string', 'max:255'],
            'zones.*.equipment_id' => ['nullable', 'integer', Rule::exists('equipment', 'id')->whereIn('installed_system_id', $systemIds)],
            'zones.*.room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->whereIn('level_id', $levelIds)],
        ], [], ['title' => 'titre', 'zones.*.number' => 'numéro de zone', 'zones.*.name' => 'nom de zone', 'contact_email' => 'e-mail']);
    }
}
