<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\BadgeTemplateResource;
use App\Models\Badge;
use App\Models\BadgeTemplate;
use App\Models\Project;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Badges (`badges.*`) : modèles (couleurs, logo, fond, champs imprimés), badges des personnes d'un projet
 * (photo, import d'une liste), impression en PDF : une carte par page ou planche A4 de 10.
 */
class BadgeController extends Controller
{
    // --- Modèles -------------------------------------------------------------------------

    public function templates(Request $request): AnonymousResourceCollection
    {
        $this->allow($request, 'badges.view');

        return BadgeTemplateResource::collection(BadgeTemplate::query()->with('client:id,name')
            ->when($request->filled('client_id'), fn ($q) => $q->where(fn ($w) => $w->where('client_id', $request->integer('client_id'))->orWhereNull('client_id')))
            ->orderBy('name')->get());
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->allow($request, 'badges.create');
        $template = BadgeTemplate::create($this->templateData($request, true));

        return (new BadgeTemplateResource($template->load('client:id,name')))->response()->setStatusCode(201);
    }

    public function updateTemplate(Request $request, BadgeTemplate $template): BadgeTemplateResource
    {
        $this->allow($request, 'badges.update');
        $template->update($this->templateData($request, false));

        return new BadgeTemplateResource($template->load('client:id,name'));
    }

    public function destroyTemplate(Request $request, BadgeTemplate $template): JsonResponse
    {
        $this->allow($request, 'badges.delete');
        $template->delete();

        return response()->json(['message' => 'Modèle supprimé.']);
    }

    /** POST /api/v1/badge-templates/{id}/images/{kind} — kind : logo ou background ; fichier `logo`. */
    public function storeTemplateImage(ClientLogoRequest $request, BadgeTemplate $template, string $kind): BadgeTemplateResource
    {
        $this->allow($request, 'badges.update');
        $column = $this->imageColumn($kind);
        $old = $template->{$column};
        $template->forceFill([$column => $request->file('logo')->store('badges/templates', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return new BadgeTemplateResource($template->load('client:id,name'));
    }

    public function destroyTemplateImage(Request $request, BadgeTemplate $template, string $kind): BadgeTemplateResource
    {
        $this->allow($request, 'badges.update');
        $column = $this->imageColumn($kind);
        if ($template->{$column}) {
            Storage::disk('public')->delete($template->{$column});
            $template->forceFill([$column => null])->save();
        }

        return new BadgeTemplateResource($template->load('client:id,name'));
    }

    // --- Badges --------------------------------------------------------------------------

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->allow($request, 'badges.view');
        $this->authorize('view', $project);

        return BadgeResource::collection($project->badges()->get());
    }

    /** POST — un badge, ou `badges` : liste importée (jusqu'à 500 personnes). */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->allow($request, 'badges.create');
        $this->authorize('view', $project);
        $rules = $this->badgeRules();
        if ($request->has('badges')) {
            $data = $request->validate(['badges' => ['required', 'array', 'min:1', 'max:500'], 'badge_template_id' => $rules['badge_template_id'],
                ...collect($rules)->except('badge_template_id')->mapWithKeys(fn ($r, $k) => ["badges.*.{$k}" => $r])->all()]);
            DB::transaction(function () use ($project, $data) {
                foreach ($data['badges'] as $row) {
                    $project->badges()->create($row + ['badge_template_id' => $data['badge_template_id'] ?? null]);
                }
            });

            return BadgeResource::collection($project->badges()->get())->response()->setStatusCode(201);
        }
        $badge = $project->badges()->create($request->validate($rules));

        return (new BadgeResource($badge))->response()->setStatusCode(201);
    }

    public function update(Request $request, Badge $badge): BadgeResource
    {
        $this->authorize('update', $badge);
        $badge->update($request->validate(collect($this->badgeRules())->map(fn ($r) => ['sometimes', ...$r])->all()));

        return new BadgeResource($badge);
    }

    public function destroy(Badge $badge): JsonResponse
    {
        $this->authorize('delete', $badge);
        $badge->delete();

        return response()->json(['message' => 'Badge supprimé.']);
    }

    /** POST /api/v1/badges/{id}/photo — `logo` : photo d'identité (JPEG, PNG, WebP, 2 Mo). */
    public function storePhoto(ClientLogoRequest $request, Badge $badge): BadgeResource
    {
        $this->authorize('update', $badge);
        $old = $badge->photo_path;
        $badge->forceFill(['photo_path' => $request->file('logo')->store('badges/photos', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return new BadgeResource($badge);
    }

    /**
     * GET /api/v1/projects/{project}/badges/pdf — `ids` (sinon tous), `layout` : cards (une carte par page,
     * pour imprimante à cartes) ou sheet (planche A4 de 10). Les badges imprimés sont datés.
     */
    public function pdf(Request $request, Project $project): Response
    {
        $this->allow($request, 'badges.view');
        $this->authorize('view', $project);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('ids', ''))));
        $badges = $project->badges()->with('template')->when($ids, fn ($q) => $q->whereKey($ids))->get();
        abort_if($badges->isEmpty(), 422, 'Aucun badge à imprimer.');
        $layout = $request->input('layout') === 'sheet' ? 'sheet' : 'cards';
        $pdf = QuotePdf::badges($badges, $layout);
        Badge::query()->whereKey($badges->pluck('id'))->update(['printed_at' => now()]);

        return $request->boolean('inline') ? $pdf->stream('Badges.pdf') : $pdf->download('Badges — '.mb_substr($project->name, 0, 60).'.pdf');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403, "Vous n'avez pas le droit de faire cette action.");
    }

    private function imageColumn(string $kind): string
    {
        return match ($kind) {
            'logo' => 'logo_path',
            'background' => 'background_path',
            default => abort(404, 'Image inconnue.'),
        };
    }

    private function badgeRules(): array
    {
        return [
            'badge_template_id' => ['nullable', 'integer', Rule::exists('badge_templates', 'id')->whereNull('deleted_at')],
            'firstname' => ['nullable', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
        ];
    }

    private function templateData(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:255'],
            'orientation' => ['sometimes', 'in:landscape,portrait'],
            'primary_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', Rule::in(array_keys(BadgeTemplate::FIELDS))],
            'footer' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['primary_color.regex' => 'Couleur au format #rrggbb.', 'text_color.regex' => 'Couleur au format #rrggbb.'], ['name' => 'nom']);
    }
}
