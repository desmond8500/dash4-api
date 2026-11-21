<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    /** GET /api/v1/contacts — filtres : search, client_id ; synchro : updated_since. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $query = Contact::query()
            ->visibleTo($request->user())
            ->with(['client:id,name', 'phones', 'emails'])
            ->when($request->filled('search'), function ($q) use ($request) {
                // Chaque mot doit se trouver dans le prénom, le nom, la société ou l'une des adresses e-mail
                foreach (preg_split('/\s+/', trim($request->string('search')->toString())) as $word) {
                    $term = '%'.$word.'%';
                    $q->where(fn ($w) => $w->where('firstname', 'like', $term)
                        ->orWhere('lastname', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('company', 'like', $term)
                        ->orWhereHas('emails', fn ($e) => $e->where('address', 'like', $term)));
                }
            })
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->orderBy('lastname')
            ->orderBy('firstname');

        SyncQuery::apply($query, $request);

        return ContactResource::collection($query->paginate(SyncQuery::perPage($request)))
            ->additional(SyncQuery::meta());
    }

    /** POST /api/v1/contacts — renvoie le contact existant si ce client_uuid a déjà été reçu. */
    public function store(ContactRequest $request): JsonResponse
    {
        $this->authorize('create', Contact::class);

        if ($request->filled('client_uuid')
            && $existing = Contact::withTrashed()->where('client_uuid', $request->input('client_uuid'))->first()) {
            return (new ContactResource($existing->load(['client:id,name', 'phones', 'emails'])))->response()->setStatusCode(200);
        }

        $contact = DB::transaction(function () use ($request) {
            $contact = new Contact($request->safe()->except(['phones', 'emails']));
            $contact->created_by = $request->user()->id;
            $contact->save();
            $contact->syncPhonesAndEmails($request->validated('phones'), $request->validated('emails'));

            return $contact;
        });

        return (new ContactResource($contact->load(['client:id,name', 'phones', 'emails'])))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/v1/contacts/{contact} */
    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return new ContactResource($contact->load(['client:id,name', 'phones', 'emails']));
    }

    /** PATCH /api/v1/contacts/{contact} */
    public function update(ContactRequest $request, Contact $contact): ContactResource
    {
        $this->authorize('update', $contact);

        DB::transaction(function () use ($request, $contact) {
            $contact->update($request->safe()->except(['phones', 'emails']));
            $contact->syncPhonesAndEmails($request->validated('phones'), $request->validated('emails'));
            $contact->touch();
        });

        return new ContactResource($contact->refresh()->load(['client:id,name', 'phones', 'emails']));
    }

    /** DELETE /api/v1/contacts/{contact} */
    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json(['message' => 'Contact supprimé.']);
    }
}
