<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientRequestResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\TaskResource;
use App\Models\ClientRequest;
use App\Models\Project;
use App\Models\Task;
use App\Support\QuoteEditing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/client-requests/{id}/convert — transforme une demande en projet ou en devis (TODO §3).
 * La demande passe à « Traitée » et garde le lien vers ce qu'elle est devenue.
 * En tâche : affectée à la personne en charge de la demande.
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
            'type' => ['required', Rule::in(['project', 'quote', 'task'])],
            'name' => ['nullable', 'string', 'max:255'],
            // Obligatoire si la demande n'est pas rattachée à un client (simple nom + téléphone)
            'client_id' => [
                Rule::requiredIf($clientRequest->client_id === null && $request->input('type') !== 'task'), 'nullable', 'integer',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ], [], ['type' => 'type', 'name' => 'nom', 'client_id' => 'client', 'manager_id' => 'chef de projet']);

        $isQuote = $data['type'] === 'quote';
        $isTask = $data['type'] === 'task';
        abort_unless(
            $user->can($isTask ? 'tasks.create' : ($isQuote ? 'quotes.create' : 'projects.create')),
            403,
            $isTask ? "Vous n'avez pas le droit de créer une tâche." : ($isQuote ? "Vous n'avez pas le droit de créer un devis." : "Vous n'avez pas le droit de créer un projet."),
        );
        $name = $data['name'] ?? Str::limit(Str::squish($clientRequest->body), 80, '…');
        $clientId = $data['client_id'] ?? $clientRequest->client_id;

        $created = DB::transaction(function () use ($data, $clientRequest, $user, $isQuote, $isTask, $name, $clientId) {
            if ($isTask) {
                $created = new Task([
                    'title' => $name,
                    'description' => $clientRequest->body,
                    'client_id' => $clientId,
                    'client_request_id' => $clientRequest->id,
                    'priority' => $clientRequest->priority->value,
                ]);
                $created->created_by = $user->id;
                $created->save();
                if ($clientRequest->assigned_to && $user->can('tasks.assign')) {
                    $created->assignees()->sync([$clientRequest->assigned_to]);
                }
            } elseif ($isQuote) {
                $created = QuoteEditing::createDraft(['client_id' => $clientId, 'title' => $name, 'notes' => $clientRequest->body], $user);
            } else {
                $created = new Project([
                    'name' => $name,
                    'description' => $clientRequest->body,
                    'client_id' => $clientId,
                    'manager_id' => $data['manager_id'] ?? null,
                    'status' => 'planned',
                ]);
                $created->created_by = $user->id;
                $created->save();
            }

            $clientRequest->forceFill([
                'converted_to_type' => $created->getMorphClass(),
                'converted_to_id' => $created->id,
            ]);
            if (! $clientRequest->status->isClosed()) {
                $clientRequest->status = ClientRequestStatus::Done;
                $clientRequest->processed_by = $user->id;
            }
            $clientRequest->save();

            return $created;
        });

        return response()->json([
            'data' => [
                'request' => new ClientRequestResource($clientRequest->refresh()->load(['client:id,name', 'assignee', 'creator', 'processor'])),
                ...match (true) {
                    $isTask => ['task' => new TaskResource($created->load(TaskController::FULL))],
                    $isQuote => ['quote' => new QuoteResource($created->load(QuoteEditing::FULL))],
                    default => ['project' => new ProjectResource($created->load(['client:id,name', 'manager:id,firstname,lastname']))],
                },
            ],
        ], 201);
    }
}
