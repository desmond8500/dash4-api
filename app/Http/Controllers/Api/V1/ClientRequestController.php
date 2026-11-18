<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRequestStatus;
use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientRequestForm;
use App\Http\Resources\ClientRequestResource;
use App\Models\ClientRequest;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientRequestController extends Controller
{
    private const RELATIONS = ['client:id,name', 'assignee:id,firstname,lastname', 'creator:id,firstname,lastname', 'processor:id,firstname,lastname'];

    /** Champs que seul un compte qui traite les demandes peut fixer (pas un technicien). */
    private const MANAGEMENT_FIELDS = ['status', 'assigned_to', 'remind_at'];

    /**
     * GET /api/v1/client-requests
     * Filtres : open=1 (À traiter), status (liste séparée par des virgules), assigned=me|none,
     * client_id, priority, source, overdue=1, search ; synchro mobile : updated_since.
     * Tri : urgentes d'abord, puis les plus récentes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ClientRequest::class);
        $user = $request->user();

        $query = ClientRequest::query()
            ->visibleTo($user)
            ->with(self::RELATIONS)
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->input('assigned') === 'me', fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->input('assigned') === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('body', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('contact_phone', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->orderByRaw(Priority::orderSql())
            ->latest('created_at')
            ->orderByDesc('id');

        SyncQuery::apply($query, $request);

        return ClientRequestResource::collection($query->paginate(SyncQuery::perPage($request)))
            ->additional(SyncQuery::meta());
    }

    /** GET /api/v1/client-requests/summary — compteurs pour le tableau de bord et le menu. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClientRequest::class);
        $user = $request->user();
        $base = fn () => ClientRequest::query()->visibleTo($user);

        return response()->json(['data' => [
            'open' => $base()->open()->count(),
            'new' => $base()->where('status', ClientRequestStatus::New->value)->count(),
            'overdue' => $base()->overdue()->count(),
            'mine' => $base()->open()->where('assigned_to', $user->id)->count(),
            'reminders_due' => $base()->open()->whereNotNull('remind_at')->where('remind_at', '<=', now())->count(),
            'overdue_days' => ClientRequest::overdueDays(),
        ]]);
    }

    /** POST /api/v1/client-requests — renvoie la demande existante si ce client_uuid a déjà été reçu. */
    public function store(ClientRequestForm $request): JsonResponse
    {
        $this->authorize('create', ClientRequest::class);

        if ($request->filled('client_uuid')
            && $existing = ClientRequest::withTrashed()->where('client_uuid', $request->input('client_uuid'))->first()) {
            return (new ClientRequestResource($existing->load(self::RELATIONS)))->response()->setStatusCode(200);
        }

        $data = $request->validated();
        if (! $request->user()->can('client_requests.update')) {
            // Un technicien note la demande ; c'est le bureau qui la traite et la confie
            $data = array_diff_key($data, array_flip(self::MANAGEMENT_FIELDS));
        }

        $clientRequest = new ClientRequest($data);
        $clientRequest->created_by = $request->user()->id;
        if ($clientRequest->status->isClosed()) {
            $clientRequest->processed_by = $request->user()->id;
        }
        $clientRequest->save();

        return (new ClientRequestResource($clientRequest->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('view', $clientRequest);

        return new ClientRequestResource($clientRequest->load(self::RELATIONS));
    }

    /** PATCH /api/v1/client-requests/{id} — changement de statut, priorité, personne en charge, rappel… */
    public function update(ClientRequestForm $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('update', $clientRequest);

        $clientRequest->fill($request->validated());
        if ($clientRequest->isDirty('status') && $clientRequest->status->isClosed()) {
            $clientRequest->processed_at = now();
            $clientRequest->processed_by = $request->user()->id;
        }
        $clientRequest->save();

        return new ClientRequestResource($clientRequest->refresh()->load(self::RELATIONS));
    }

    public function destroy(ClientRequest $clientRequest): JsonResponse
    {
        $this->authorize('delete', $clientRequest);

        $clientRequest->delete();

        return response()->json(['message' => 'Demande supprimée.']);
    }
}
