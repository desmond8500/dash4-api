<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Support\Attachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Liens d'une fiche : /api/v1/{type}/{id}/links, puis /api/v1/links/{link}. */
class LinkController extends Controller
{
    /** Adresses web seulement : pas de javascript:, data:, file:… */
    private const URL_RULE = ['url:http,https', 'max:2048'];

    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeView($request->user(), $parent);

        return LinkResource::collection($parent->links()->with('creator:id,firstname,lastname')->get());
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeAdd($request->user(), $parent);

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'label' => ['nullable', 'string', 'max:255'],
            'url' => ['required', ...self::URL_RULE],
        ], [], ['url' => 'adresse', 'label' => 'libellé']);

        if (! empty($data['client_uuid']) && $existing = Link::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new LinkResource($existing))->response()->setStatusCode(200);
        }

        $link = $parent->links()->make($data);
        $link->created_by = $request->user()->id;
        $link->save();
        $parent->touch();

        return (new LinkResource($link->load('creator:id,firstname,lastname')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Link $link): LinkResource
    {
        $parent = $link->linkable()->withTrashed()->firstOrFail();
        Attachable::authorizeChange($request->user(), $link, $parent);

        $link->update($request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'url' => ['sometimes', ...self::URL_RULE],
        ], [], ['url' => 'adresse', 'label' => 'libellé']));
        $parent->touch();

        return new LinkResource($link->load('creator:id,firstname,lastname'));
    }

    public function destroy(Request $request, Link $link): JsonResponse
    {
        $parent = $link->linkable()->withTrashed()->firstOrFail();
        Attachable::authorizeChange($request->user(), $link, $parent);

        $link->delete();
        $parent->touch();

        return response()->json(['message' => 'Lien supprimé.']);
    }
}
