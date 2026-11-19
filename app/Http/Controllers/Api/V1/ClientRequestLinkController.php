<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientRequestResource;
use App\Models\ClientRequest;
use App\Models\ClientRequestLink;
use App\Support\ClientRequestLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lier une demande client à des tâches, factures, bordereaux, devis ou projets (qui peut traiter la demande). */
class ClientRequestLinkController extends Controller
{
    /** GET /api/v1/client-requests/link-candidates?type=invoice&search=FAC — fiches visibles à lier. */
    public function candidates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('client_requests.update'), 403);
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(ClientRequestLinks::TYPES))], 'search' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => ClientRequestLinks::search($request->user(), $data['type'], (string) ($data['search'] ?? ''))]);
    }

    /** POST /api/v1/client-requests/{id}/links — `type`, `id`. */
    public function store(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('update', $clientRequest);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ClientRequestLinks::TYPES))],
            'id' => ['required', 'integer'],
        ], [], ['type' => 'type', 'id' => 'fiche']);
        $target = ClientRequestLinks::visible($request->user(), $data['type'], $data['id']);
        abort_unless($target, 422, 'Fiche introuvable, ou vous n\'y avez pas accès.');

        $clientRequest->links()->firstOrCreate(
            ['linkable_type' => $data['type'], 'linkable_id' => $target->getKey()],
            ['created_by' => $request->user()->id],
        );

        return new ClientRequestResource($clientRequest->refresh()->load(ClientRequestController::RELATIONS));
    }

    public function destroy(Request $request, ClientRequestLink $link): ClientRequestResource
    {
        $clientRequest = ClientRequest::findOrFail($link->client_request_id);
        $this->authorize('update', $clientRequest);
        $link->delete();

        return new ClientRequestResource($clientRequest->refresh()->load(ClientRequestController::RELATIONS));
    }
}
