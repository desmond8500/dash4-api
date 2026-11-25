<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordContactResource;
use App\Models\Contact;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RecordContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Contacts liés à un projet ou à un devis : /api/v1/{projects|quotes}/{id}/contacts, puis /api/v1/record-contacts/{id}.
 * Voir la fiche pour lire ; pouvoir la modifier (même un devis accepté) et voir les contacts pour lier ou délier.
 * Un contact peut venir d'un autre client (architecte, bureau de contrôle…).
 */
class RecordContactController extends Controller
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = ['projects' => Project::class, 'quotes' => Quote::class];

    private const RELATIONS = ['contact.client:id,name', 'contact.phones', 'contact.emails'];

    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = $this->parent($type, $id);
        Gate::authorize('view', $parent);

        return RecordContactResource::collection($parent->linkedContacts()->with(self::RELATIONS)->get());
    }

    /** POST — `contact_id`, `role` facultatif ; un contact déjà lié garde son lien (le rôle est mis à jour). */
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $parent = $this->parent($type, $id);
        $this->authorizeChange($request, $parent);
        $data = $request->validate([
            'contact_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:100'],
        ], [], ['contact_id' => 'contact', 'role' => 'rôle']);

        $contact = Contact::query()->visibleTo($request->user())->find($data['contact_id']);
        abort_unless($contact, 422, 'Contact introuvable.');

        $existing = $parent->linkedContacts()->where('contact_id', $contact->id)->first();
        if ($existing) {
            if (array_key_exists('role', $data)) {
                $existing->update(['role' => $data['role']]);
            }

            return (new RecordContactResource($existing->load(self::RELATIONS)))->response()->setStatusCode(200);
        }

        $link = $parent->linkedContacts()->make([
            'contact_id' => $contact->id,
            'role' => $data['role'] ?? null,
            'position' => (int) $parent->linkedContacts()->max('position') + 1,
        ]);
        $link->created_by = $request->user()->id;
        $link->save();
        $parent->touch();

        return (new RecordContactResource($link->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /** PATCH — rôle du contact sur la fiche. */
    public function update(Request $request, RecordContact $recordContact): RecordContactResource
    {
        $parent = $recordContact->contactable()->withTrashed()->firstOrFail();
        $this->authorizeChange($request, $parent);
        $recordContact->update($request->validate(['role' => ['present', 'nullable', 'string', 'max:100']], [], ['role' => 'rôle']));
        $parent->touch();

        return new RecordContactResource($recordContact->load(self::RELATIONS));
    }

    /** POST {type}/{id}/contacts/order — `ids` dans le nouvel ordre. */
    public function reorder(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = $this->parent($type, $id);
        $this->authorizeChange($request, $parent);
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']], [], ['ids' => 'ordre'])['ids'];

        DB::transaction(function () use ($parent, $ids) {
            foreach (array_values($ids) as $position => $linkId) {
                $parent->linkedContacts()->whereKey($linkId)->update(['position' => $position]);
            }
        });
        $parent->touch();

        return RecordContactResource::collection($parent->linkedContacts()->with(self::RELATIONS)->get());
    }

    /** DELETE — délie le contact (le contact lui-même reste). */
    public function destroy(Request $request, RecordContact $recordContact): JsonResponse
    {
        $parent = $recordContact->contactable()->withTrashed()->firstOrFail();
        $this->authorizeChange($request, $parent);
        $recordContact->delete();
        $parent->touch();

        return response()->json(['message' => 'Contact retiré.']);
    }

    private function parent(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404, "Type de fiche inconnu : {$type}.");

        return $class::query()->findOrFail($id);
    }

    private function authorizeChange(Request $request, Model $parent): void
    {
        Gate::authorize('update', $parent);
        abort_unless($request->user()->can('clients.view'), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
