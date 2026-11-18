<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientRequestResource;
use App\Http\Resources\ProjectResource;
use App\Models\ClientRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/client-requests/{id}/convert — transforme une demande en projet (TODO §3).
 * La demande passe à « Traitée » et garde le lien vers le projet créé.
 * Tâche, devis et intervention s'ajouteront ici quand ces modules existeront.
 */
class ClientRequestConvertController extends Controller
{
    public function __invoke(Request $request, ClientRequest $clientRequest): JsonResponse
    {
        $this->authorize('update', $clientRequest);
        $user = $request->user();
        abort_unless($user->can('client_requests.convert'), 403, "Vous n'avez pas le droit de faire cette action.");
        abort_if($clientRequest->converted_to_type !== null, 422, 'Cette demande a déjà été transformée.');

        $data = $request->validate([
            'type' => ['required', Rule::in(['project'])],
            'name' => ['nullable', 'string', 'max:255'],
            // Obligatoire si la demande n'est pas rattachée à un client (simple nom + téléphone)
            'client_id' => [
                Rule::requiredIf($clientRequest->client_id === null), 'nullable', 'integer',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ], [], ['type' => 'type', 'name' => 'nom', 'client_id' => 'client', 'manager_id' => 'chef de projet']);

        abort_unless($user->can('projects.create'), 403, "Vous n'avez pas le droit de créer un projet.");

        $project = DB::transaction(function () use ($data, $clientRequest, $user) {
            $project = new Project([
                'name' => $data['name'] ?? Str::limit(Str::squish($clientRequest->body), 80, '…'),
                'description' => $clientRequest->body,
                'client_id' => $data['client_id'] ?? $clientRequest->client_id,
                'manager_id' => $data['manager_id'] ?? null,
                'status' => 'planned',
            ]);
            $project->created_by = $user->id;
            $project->save();

            $clientRequest->forceFill([
                'converted_to_type' => $project->getMorphClass(),
                'converted_to_id' => $project->id,
            ]);
            if (! $clientRequest->status->isClosed()) {
                $clientRequest->status = ClientRequestStatus::Done;
                $clientRequest->processed_by = $user->id;
            }
            $clientRequest->save();

            return $project;
        });

        return response()->json([
            'data' => [
                'request' => new ClientRequestResource($clientRequest->refresh()->load(['client:id,name', 'assignee', 'creator', 'processor'])),
                'project' => new ProjectResource($project->load(['client:id,name', 'manager:id,firstname,lastname'])),
            ],
        ], 201);
    }
}
