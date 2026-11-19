<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\CvResource;
use App\Models\Cv;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * CV de l'espace personnel : chacun gère les siens (plusieurs versions), les partage s'il veut
 * avec les administrateurs, et reprend en un clic les projets Dash4 auxquels il a participé.
 */
class CvController extends Controller
{
    private const WITH = ['sections.items'];

    /** GET /api/v1/me/cvs */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CvResource::collection(Cv::query()->where('user_id', $request->user()->id)->with(self::WITH)->latest('updated_at')->get());
    }

    /** GET /api/v1/users/{user}/cvs — CV partagés d'un utilisateur (administrateur). */
    public function shared(Request $request, User $user): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRole('admin'), 403, "Vous n'avez pas le droit de faire cette action.");

        return CvResource::collection(Cv::query()->where('user_id', $user->id)->where('shared', true)->with(self::WITH)->get());
    }

    /** POST /api/v1/me/cvs — nouveau CV, prérempli avec le profil et les sections habituelles. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, true);
        $cv = DB::transaction(function () use ($user, $data) {
            $cv = new Cv($data + ['fullname' => $user->full_name, 'title' => $user->job_title, 'email' => $user->email, 'phone' => $user->phone]);
            $cv->user_id = $user->id;
            $cv->save();
            foreach (['experience', 'education', 'skills', 'languages'] as $i => $type) {
                $cv->sections()->create(['name' => Cv::SECTION_TYPES[$type], 'type' => $type, 'position' => $i]);
            }

            return $cv;
        });

        return (new CvResource($cv->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(Cv $cv): CvResource
    {
        $this->authorize('view', $cv);

        return new CvResource($cv->load(self::WITH));
    }

    /** PATCH — en-tête et mise en page ; `sections` envoyées (avec leurs `items`) : remplacent tout le contenu. */
    public function update(Request $request, Cv $cv): CvResource
    {
        $this->authorize('update', $cv);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($cv, $data) {
            $cv->update(array_diff_key($data, ['sections' => true]));
            if (array_key_exists('sections', $data)) {
                $cv->sections()->delete();
                foreach (array_values($data['sections']) as $i => $s) {
                    $section = $cv->sections()->create(['name' => $s['name'], 'type' => $s['type'] ?? 'free', 'position' => $i]);
                    foreach (array_values($s['items'] ?? []) as $j => $item) {
                        $section->items()->create([...$item, 'position' => $j]);
                    }
                }
            }
        });

        return new CvResource($cv->refresh()->load(self::WITH));
    }

    public function destroy(Cv $cv): JsonResponse
    {
        $this->authorize('delete', $cv);
        $cv->delete();

        return response()->json(['message' => 'CV supprimé.']);
    }

    /** POST /api/v1/cvs/{id}/duplicate — copie complète, pour adapter une version. */
    public function duplicate(Request $request, Cv $cv): JsonResponse
    {
        $this->authorize('update', $cv);
        $copy = DB::transaction(function () use ($cv) {
            $copy = $cv->replicate(['photo_path']);
            $copy->name = mb_substr("{$cv->name} (copie)", 0, 255);
            $copy->shared = false;
            if ($cv->photo_path && Storage::disk('public')->exists($cv->photo_path)) {
                $copy->photo_path = 'cvs/'.uniqid('', true).'.'.pathinfo($cv->photo_path, PATHINFO_EXTENSION);
                Storage::disk('public')->copy($cv->photo_path, $copy->photo_path);
            }
            $copy->save();
            foreach ($cv->sections()->with('items')->get() as $section) {
                $newSection = $copy->sections()->create($section->only(['name', 'type', 'position']));
                foreach ($section->items as $item) {
                    $newSection->items()->create($item->only(['title', 'subtitle', 'place', 'start_date', 'end_date', 'current', 'description', 'level', 'position']));
                }
            }

            return $copy;
        });

        return (new CvResource($copy->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function photo(ClientLogoRequest $request, Cv $cv): CvResource
    {
        $this->authorize('update', $cv);
        $old = $cv->photo_path;
        $cv->forceFill(['photo_path' => $request->file('logo')->store('cvs', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return new CvResource($cv->load(self::WITH));
    }

    /**
     * POST /api/v1/cvs/{id}/projects — ajoute (ou complète) la section « Projets réalisés » avec les projets
     * Dash4 auxquels l'auteur a participé : chef de projet, tâches affectées ou journaux.
     */
    public function importProjects(Request $request, Cv $cv): CvResource
    {
        $this->authorize('update', $cv);
        $user = $cv->user;
        $ids = Project::query()->where('manager_id', $user->id)->pluck('id')
            ->merge(Task::query()->whereHas('assignees', fn ($q) => $q->whereKey($user->id))->whereNotNull('project_id')->pluck('project_id'))
            ->merge(Journal::query()->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))->whereNotNull('project_id')->pluck('project_id'))
            ->unique()->values();
        $projects = Project::query()->whereKey($ids)->with('client:id,name')->orderByDesc('start_date')->limit(40)->get();

        DB::transaction(function () use ($cv, $projects, $user) {
            $section = $cv->sections()->where('type', 'projects')->first()
                ?? $cv->sections()->create(['name' => Cv::SECTION_TYPES['projects'], 'type' => 'projects', 'position' => $cv->sections()->max('position') + 1]);
            $existing = $section->items()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
            $position = (int) $section->items()->max('position');
            foreach ($projects as $p) {
                if (in_array(mb_strtolower($p->name), $existing, true)) {
                    continue;
                }
                $section->items()->create([
                    'title' => $p->name,
                    'subtitle' => $p->client?->name,
                    'start_date' => $p->start_date?->format('m/Y'),
                    'end_date' => $p->end_date?->format('m/Y'),
                    'description' => $p->manager_id === $user->id ? 'Chef de projet.' : null,
                    'position' => ++$position,
                ]);
            }
        });

        return new CvResource($cv->refresh()->load(self::WITH));
    }

    public function pdf(Request $request, Cv $cv): Response
    {
        $this->authorize('view', $cv);
        $pdf = QuotePdf::cv($cv);
        $name = 'CV — '.mb_substr($cv->fullname ?: $cv->name, 0, 80).'.pdf';

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fullname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['sometimes', Rule::in(['sans', 'serif', 'mono'])],
            'layout' => ['sometimes', Rule::in(['one_column', 'two_columns'])],
            'show_photo' => ['sometimes', 'boolean'],
            'shared' => ['sometimes', 'boolean'],
            'sections' => ['sometimes', 'array', 'max:30'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.type' => ['nullable', Rule::in(array_keys(Cv::SECTION_TYPES))],
            'sections.*.items' => ['nullable', 'array', 'max:100'],
            'sections.*.items.*.title' => ['required', 'string', 'max:255'],
            'sections.*.items.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.items.*.place' => ['nullable', 'string', 'max:255'],
            'sections.*.items.*.start_date' => ['nullable', 'string', 'max:20'],
            'sections.*.items.*.end_date' => ['nullable', 'string', 'max:20'],
            'sections.*.items.*.current' => ['nullable', 'boolean'],
            'sections.*.items.*.description' => ['nullable', 'string', 'max:5000'],
            'sections.*.items.*.level' => ['nullable', 'integer', 'between:1,5'],
        ], ['accent_color.regex' => 'Couleur au format #rrggbb.'], [
            'name' => 'nom du CV', 'sections.*.name' => 'nom de section', 'sections.*.items.*.title' => 'intitulé', 'email' => 'e-mail',
        ]);
    }
}
