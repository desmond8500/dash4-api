<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JournalType;
use App\Http\Controllers\Controller;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use App\Models\Task;
use App\Support\QuotePdf;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journaux d'intervention : sections, intervenants, photos (documents), position, PDF.
 * Depuis une tâche (`task_id`) : projet, client et intervenants repris de la tâche.
 */
class JournalController extends Controller
{
    public const FULL = ['project:id,name,manager_id', 'client:id,name', 'quote:id,number', 'task:id,title', 'sections', 'participants.user:id,firstname,lastname', 'participants.contact', 'creator:id,firstname,lastname'];

    /** GET /api/v1/journals — filtres : project_id, client_id, task_id, type, from, to, mine=1, search ; synchro : updated_since. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Journal::class);
        $user = $request->user();
        $query = Journal::query()->visibleTo($user)->with(self::FULL)->withCount('documents')
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('task_id'), fn ($q) => $q->where('task_id', $request->integer('task_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id))))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->latest('date')->orderByDesc('id');
        SyncQuery::apply($query, $request);

        return JournalResource::collection($query->paginate(SyncQuery::perPage($request, 50)))->additional(SyncQuery::meta());
    }

    /**
     * POST /api/v1/journals — `sections` [{title, body}], `participants` [{user_id | contact_id | name, role}] ;
     * l'auteur est intervenant d'office.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Journal::class);
        $data = $this->validated($request, true);
        if (! empty($data['client_uuid']) && $existing = Journal::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new JournalResource($existing->load(self::FULL)->loadCount('documents')))->response()->setStatusCode(200);
        }
        $user = $request->user();
        $task = ! empty($data['task_id']) ? Task::with('assignees:id')->find($data['task_id']) : null;
        if ($task) {
            abort_unless($user->can('view', $task), 403, "Vous n'avez pas accès à cette tâche.");
            $data += ['project_id' => $task->project_id, 'client_id' => $task->client_id];
        }

        $journal = DB::transaction(function () use ($data, $user, $task) {
            $journal = new Journal(array_diff_key($data, ['sections' => true, 'participants' => true]));
            $journal->created_by = $user->id;
            $journal->save();
            $this->writeSections($journal, $data['sections'] ?? []);
            $participants = $data['participants'] ?? ($task ? $task->assignees->map(fn ($a) => ['user_id' => $a->id])->all() : []);
            if (! collect($participants)->contains('user_id', $user->id)) {
                array_unshift($participants, ['user_id' => $user->id]);
            }
            $this->writeParticipants($journal, $participants);

            return $journal;
        });

        return (new JournalResource($journal->refresh()->load(self::FULL)->loadCount('documents')))->response()->setStatusCode(201);
    }

    public function show(Journal $journal): JournalResource
    {
        $this->authorize('view', $journal);

        return new JournalResource($journal->load(self::FULL)->loadCount('documents'));
    }

    /** PATCH — `sections` et `participants` envoyés : remplacent la liste. */
    public function update(Request $request, Journal $journal): JournalResource
    {
        $this->authorize('update', $journal);
        $data = array_diff_key($this->validated($request, false), ['client_uuid' => true]);
        DB::transaction(function () use ($journal, $data) {
            $journal->update(array_diff_key($data, ['sections' => true, 'participants' => true]));
            if (array_key_exists('sections', $data)) {
                $journal->sections()->delete();
                $this->writeSections($journal, $data['sections']);
            }
            if (array_key_exists('participants', $data)) {
                $journal->participants()->delete();
                $this->writeParticipants($journal, $data['participants']);
            }
        });

        return new JournalResource($journal->refresh()->load(self::FULL)->loadCount('documents'));
    }

    public function destroy(Journal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);
        $journal->delete();

        return response()->json(['message' => 'Journal supprimé.']);
    }

    /** GET /api/v1/journals/{id}/pdf — rapport d'intervention avec les photos. */
    public function pdf(Request $request, Journal $journal): Response
    {
        $this->authorize('view', $journal);
        $pdf = QuotePdf::journal($journal);
        $name = 'Rapport '.$journal->date->format('Y-m-d').' — '.mb_substr($journal->title, 0, 60).'.pdf';

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    private function writeSections(Journal $journal, array $sections): void
    {
        foreach (array_values($sections) as $i => $s) {
            $journal->sections()->create(['position' => $i, 'title' => $s['title'], 'body' => $s['body'] ?? null]);
        }
    }

    private function writeParticipants(Journal $journal, array $participants): void
    {
        $seen = [];
        foreach ($participants as $p) {
            $key = isset($p['user_id']) ? 'u'.$p['user_id'] : (isset($p['contact_id']) ? 'c'.$p['contact_id'] : 'n'.($p['name'] ?? ''));
            if (isset($seen[$key]) || (empty($p['user_id']) && empty($p['contact_id']) && empty($p['name']))) {
                continue;
            }
            $seen[$key] = true;
            $journal->participants()->create(array_intersect_key($p, array_flip(['user_id', 'contact_id', 'name', 'role'])));
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'title' => [$required, 'string', 'max:255'],
            'type' => ['sometimes', new Enum(JournalType::class)],
            'date' => [$required, 'date'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', ...($request->filled('starts_at') ? ['after_or_equal:starts_at'] : [])],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'quote_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quotes', 'id')->whereNull('deleted_at')],
            'task_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
            'summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'sections' => ['sometimes', 'array', 'max:50'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'participants' => ['sometimes', 'array', 'max:50'],
            'participants.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'participants.*.contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')],
            'participants.*.name' => ['nullable', 'string', 'max:255'],
            'participants.*.role' => ['nullable', 'string', 'max:100'],
        ], ['ends_at.after_or_equal' => 'La fin doit être après le début.'], [
            'title' => 'titre', 'date' => 'date', 'starts_at' => 'début', 'ends_at' => 'fin', 'project_id' => 'projet',
            'summary' => 'résumé', 'sections.*.title' => 'titre de la section', 'sections.*.body' => 'contenu de la section',
        ]);
    }
}
