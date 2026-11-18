<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Support\Attachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Notes d'une fiche : /api/v1/{type}/{id}/notes, puis /api/v1/notes/{note}. */
class NoteController extends Controller
{
    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeView($request->user(), $parent);

        return NoteResource::collection($parent->notes()->with('creator:id,firstname,lastname')->get());
    }

    /** Anti-doublon client_uuid pour les notes écrites hors ligne sur le mobile. */
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeAdd($request->user(), $parent);

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ], [], ['body' => 'note']);

        if (! empty($data['client_uuid']) && $existing = Note::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new NoteResource($existing->load('creator:id,firstname,lastname')))->response()->setStatusCode(200);
        }

        $note = $parent->notes()->make($data);
        $note->created_by = $request->user()->id;
        $note->save();
        $parent->touch();

        return (new NoteResource($note->load('creator:id,firstname,lastname')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Note $note): NoteResource
    {
        $parent = $note->noteable()->withTrashed()->firstOrFail();
        Attachable::authorizeChange($request->user(), $note, $parent);

        $note->update($request->validate([
            'body' => ['sometimes', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ], [], ['body' => 'note']));
        $parent->touch();

        return new NoteResource($note->load('creator:id,firstname,lastname'));
    }

    public function destroy(Request $request, Note $note): JsonResponse
    {
        $parent = $note->noteable()->withTrashed()->firstOrFail();
        Attachable::authorizeChange($request->user(), $note, $parent);

        $note->delete();
        $parent->touch();

        return response()->json(['message' => 'Note supprimée.']);
    }
}
