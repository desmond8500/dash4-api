<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * GET /api/v1/clients
     * Filtres : search, type, status, favorite ; tri : sort=name|-updated_at ;
     * synchro mobile : updated_since (voir SyncQuery).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Client::class);

        $query = Client::query()
            ->visibleTo($request->user())
            ->with(['phones', 'emails'])
            ->withCount('contacts')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('address', 'like', $term));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('favorite'), fn ($q) => $q->where('is_favorite', true))
            ->when(
                $request->string('sort')->toString() === '-updated_at',
                fn ($q) => $q->latest('updated_at'),
                fn ($q) => $q->orderByDesc('is_favorite')->orderBy('name'),
            );

        SyncQuery::apply($query, $request);

        return ClientResource::collection($query->paginate(SyncQuery::perPage($request)))
            ->additional(SyncQuery::meta());
    }

    /** POST /api/v1/clients — renvoie le client existant si ce client_uuid a déjà été reçu. */
    public function store(ClientRequest $request): JsonResponse
    {
        $this->authorize('create', Client::class);

        if ($request->filled('client_uuid')
            && $existing = Client::withTrashed()->where('client_uuid', $request->input('client_uuid'))->first()) {
            return (new ClientResource($existing->load(['phones', 'emails'])))->response()->setStatusCode(200);
        }

        $client = DB::transaction(function () use ($request) {
            $client = new Client($request->safe()->except(['phones', 'emails']));
            $client->created_by = $request->user()->id;
            $client->save();
            $client->syncPhonesAndEmails($request->validated('phones'), $request->validated('emails'));

            return $client;
        });

        return (new ClientResource($client->load(['phones', 'emails'])->loadCount('contacts')))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/v1/clients/{client} — avec ses contacts, téléphones et e-mails. */
    public function show(Client $client): ClientResource
    {
        $this->authorize('view', $client);

        return new ClientResource($client->load(['phones', 'emails', 'contacts.phones', 'contacts.emails'])->loadCount('contacts'));
    }

    /** PATCH /api/v1/clients/{client} — `phones` / `emails` envoyés remplacent les listes existantes. */
    public function update(ClientRequest $request, Client $client): ClientResource
    {
        $this->authorize('update', $client);

        DB::transaction(function () use ($request, $client) {
            $client->update($request->safe()->except(['phones', 'emails']));
            $client->syncPhonesAndEmails($request->validated('phones'), $request->validated('emails'));
            $client->touch();
        });

        return new ClientResource($client->refresh()->load(['phones', 'emails'])->loadCount('contacts'));
    }

    /** DELETE /api/v1/clients/{client} — mise à la corbeille (suppression logique). */
    public function destroy(Client $client): JsonResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return response()->json(['message' => 'Client supprimé.']);
    }
}
