<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReceptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\ReceptionReportResource;
use App\Models\Project;
use App\Models\ReceptionReport;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/** Procès-verbaux de réception d'un chantier (`sites.*`) : parties et logos, systèmes, réserves, PDF. */
class ReceptionReportController extends Controller
{
    private const WITH = ['project:id,name,client_id,manager_id,created_by', 'quote:id,number,title', 'systems', 'creator:id,firstname,lastname'];

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewSite', $project);

        return ReceptionReportResource::collection($project->receptionReports()->with(self::WITH)->get());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('sites.create'), 403, "Vous n'avez pas le droit de faire cette action.");
        $this->authorize('manageSite', $project);
        $data = $this->validated($request, $project, true);

        $report = DB::transaction(function () use ($project, $data, $request) {
            $report = $project->receptionReports()->make(array_diff_key($data, ['system_ids' => true]) + [
                'client_name' => $project->client?->name,
            ]);
            $report->created_by = $request->user()->id;
            $report->save();
            $report->systems()->sync($data['system_ids'] ?? $project->installedSystems()->pluck('id')->all());

            return $report;
        });

        return (new ReceptionReportResource($report->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(ReceptionReport $receptionReport): ReceptionReportResource
    {
        $this->authorize('view', $receptionReport);

        return new ReceptionReportResource($receptionReport->load(self::WITH));
    }

    public function update(Request $request, ReceptionReport $receptionReport): ReceptionReportResource
    {
        $this->authorize('update', $receptionReport);
        $data = $this->validated($request, $receptionReport->project, false);
        DB::transaction(function () use ($receptionReport, $data) {
            $receptionReport->update(array_diff_key($data, ['system_ids' => true]));
            if (array_key_exists('system_ids', $data)) {
                $receptionReport->systems()->sync($data['system_ids']);
            }
        });

        return new ReceptionReportResource($receptionReport->refresh()->load(self::WITH));
    }

    public function destroy(ReceptionReport $receptionReport): JsonResponse
    {
        $this->authorize('delete', $receptionReport);
        $receptionReport->delete();

        return response()->json(['message' => 'Procès-verbal supprimé.']);
    }

    /** POST /api/v1/reception-reports/{id}/logos/{party} — `logo` : JPEG, PNG ou WebP, 2 Mo. */
    public function storeLogo(ClientLogoRequest $request, ReceptionReport $receptionReport, string $party): ReceptionReportResource
    {
        $this->authorize('update', $receptionReport);
        $column = $this->logoColumn($party);
        $old = $receptionReport->{$column};
        $path = $request->file('logo')->store('reception-reports/logos', 'public');
        $receptionReport->forceFill([$column => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return new ReceptionReportResource($receptionReport->refresh()->load(self::WITH));
    }

    public function destroyLogo(ReceptionReport $receptionReport, string $party): ReceptionReportResource
    {
        $this->authorize('update', $receptionReport);
        $column = $this->logoColumn($party);
        if ($receptionReport->{$column}) {
            Storage::disk('public')->delete($receptionReport->{$column});
            $receptionReport->forceFill([$column => null])->save();
        }

        return new ReceptionReportResource($receptionReport->refresh()->load(self::WITH));
    }

    public function pdf(Request $request, ReceptionReport $receptionReport): Response
    {
        $this->authorize('view', $receptionReport);
        $pdf = QuotePdf::receptionReport($receptionReport);
        $name = PdfName::receptionReport($receptionReport->loadMissing('project.client'));

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    private function logoColumn(string $party): string
    {
        abort_unless(array_key_exists($party, ReceptionReport::PARTIES), 404, 'Partie inconnue.');

        return "{$party}_logo_path";
    }

    private function validated(Request $request, Project $project, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date' => [$required, 'date'],
            'status' => ['sometimes', new Enum(ReceptionStatus::class)],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quote_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quotes', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'engineer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inspector_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'reserves' => ['sometimes', 'nullable', 'array', 'max:100'],
            'reserves.*.text' => ['required', 'string', 'max:1000'],
            'reserves.*.lifted_at' => ['nullable', 'date'],
            'system_ids' => ['sometimes', 'array'],
            'system_ids.*' => ['integer', Rule::exists('installed_systems', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
        ], ['quote_id.exists' => "Ce devis n'est pas sur ce projet.", 'system_ids.*.exists' => "Ce système n'est pas sur ce projet."], [
            'title' => 'titre', 'date' => 'date', 'reserves.*.text' => 'réserve', 'observations' => 'observations',
        ]);
    }
}
