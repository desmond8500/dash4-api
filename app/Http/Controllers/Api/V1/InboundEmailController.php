<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRequestSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResources;
use App\Http\Resources\ClientRequestResource;
use App\Models\ClientRequest;
use App\Models\Document;
use App\Models\InboundEmail;
use App\Support\Inbox\ImapMailbox;
use App\Support\Inbox\InboxFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boîte de réception des e-mails reçus sur l'adresse des demandes : lecture, transformation en
 * demande client (pièces jointes reprises en documents), ou « ignorer ». Réservé à client_requests.update.
 */
class InboundEmailController extends Controller
{
    /** GET /api/v1/inbound-emails — `status` (new par défaut, converted, ignored, all), `search`. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAllowed($request);
        $status = $request->input('status', 'new');
        $page = InboundEmail::query()->with('client:id,name')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $term)->orWhere('from_email', 'like', $term)->orWhere('from_name', 'like', $term));
            })
            ->latest('received_at')->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return response()->json([
            'data' => collect($page->items())->map(fn (InboundEmail $e) => $this->present($e, false)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'new_count' => InboundEmail::where('status', 'new')->count(),
                'configured' => (bool) config('dash4.inbox.host'),
                'address' => config('dash4.inbox.address') ?: config('dash4.inbox.username'),
            ],
        ]);
    }

    public function show(Request $request, InboundEmail $email): JsonResponse
    {
        $this->ensureAllowed($request);

        return response()->json(['data' => $this->present($email->load(['client:id,name', 'handler:id,firstname,lastname']), true)]);
    }

    /** POST /api/v1/inbound-emails/fetch — relever la boîte tout de suite. */
    public function fetch(Request $request): JsonResponse
    {
        $this->ensureAllowed($request);
        abort_unless(config('dash4.inbox.host') && config('dash4.inbox.username'), 422, 'La boîte mail n\'est pas configurée sur le serveur (INBOX_HOST, INBOX_USERNAME, INBOX_PASSWORD).');
        try {
            $result = InboxFetcher::run(app()->bound('dash4.mailbox') ? app('dash4.mailbox') : ImapMailbox::fromConfig());
        } catch (\RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        return response()->json(['data' => $result, 'message' => "{$result['imported']} nouvel(s) e-mail(s)."]);
    }

    /**
     * POST /api/v1/inbound-emails/{id}/convert — crée la demande (source e-mail) ; `client_id`,
     * `body`, `priority`, `assigned_to` facultatifs. Les pièces jointes deviennent des documents.
     */
    public function convert(Request $request, InboundEmail $email): JsonResponse
    {
        $this->ensureAllowed($request);
        abort_if($email->client_request_id !== null, 422, 'Cet e-mail est déjà une demande.');
        $data = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'body' => ['sometimes', 'string', 'max:10000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ], [], ['client_id' => 'client', 'body' => 'demande', 'assigned_to' => 'personne en charge']);
        $user = $request->user();

        $clientRequest = DB::transaction(function () use ($email, $data, $user) {
            $body = $data['body'] ?? trim($email->subject."\n\n".Str::limit((string) $email->body_text, 8000, '…'));
            $clientRequest = new ClientRequest([
                'body' => $body,
                'client_id' => array_key_exists('client_id', $data) ? $data['client_id'] : $email->client_id,
                'contact_name' => $email->from_name ?? $email->from_email,
                'contact_email' => $email->from_email,
                'source' => ClientRequestSource::Email,
                'priority' => $data['priority'] ?? 'normal',
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);
            $clientRequest->created_by = $user->id;
            $clientRequest->save();

            foreach ($email->attachments ?? [] as $a) {
                if (! Storage::disk('local')->exists($a['path'])) {
                    continue;
                }
                $path = 'documents/client_requests/'.$clientRequest->id.'/'.basename($a['path']);
                Storage::disk(Document::DISK)->put($path, Storage::disk('local')->get($a['path']));
                $document = new Document(['name' => $a['name'], 'path' => $path, 'mime_type' => $a['mime'], 'size' => $a['size']]);
                $document->documentable()->associate($clientRequest);
                $document->created_by = $user->id;
                $document->save();
            }

            $email->forceFill(['status' => 'converted', 'client_request_id' => $clientRequest->id, 'handled_by' => $user->id, 'handled_at' => now()])->save();

            return $clientRequest;
        });

        return response()->json(['data' => [
            'email' => $this->present($email->refresh()->load('client:id,name'), false),
            'request' => new ClientRequestResource($clientRequest->load(ClientRequestController::RELATIONS)),
        ]], 201);
    }

    /** POST /api/v1/inbound-emails/{id}/ignore — publicité, doublon… ; `restore=1` pour la remettre. */
    public function ignore(Request $request, InboundEmail $email): JsonResponse
    {
        $this->ensureAllowed($request);
        abort_if($email->status === 'converted', 422, 'Cet e-mail est déjà une demande.');
        $restore = $request->boolean('restore');
        $email->forceFill(['status' => $restore ? 'new' : 'ignored', 'handled_by' => $restore ? null : $request->user()->id, 'handled_at' => $restore ? null : now()])->save();

        return response()->json(['data' => $this->present($email, false)]);
    }

    /** GET /api/v1/inbound-emails/{id}/attachments/{index} */
    public function attachment(Request $request, InboundEmail $email, int $index): Response
    {
        $this->ensureAllowed($request);
        $a = ($email->attachments ?? [])[$index] ?? abort(404, 'Pièce jointe introuvable.');
        abort_unless(Storage::disk('local')->exists($a['path']), 404, 'Pièce jointe introuvable.');

        return Storage::disk('local')->download($a['path'], $a['name'], ['Content-Type' => $a['mime']]);
    }

    private function ensureAllowed(Request $request): void
    {
        abort_unless($request->user()->can('client_requests.update'), 403, "Vous n'avez pas accès à la boîte mail des demandes.");
    }

    private function present(InboundEmail $e, bool $full): array
    {
        return [
            'id' => $e->id,
            'from_email' => $e->from_email,
            'from_name' => $e->from_name,
            'to' => $e->to,
            'subject' => $e->subject,
            'preview' => Str::limit(Str::squish((string) $e->body_text), 160),
            ...($full ? ['body_text' => $e->body_text, 'has_html' => $e->body_html !== null] : []),
            'received_at' => $e->received_at?->toIso8601String(),
            'status' => ['value' => $e->status, 'label' => ['new' => 'Nouveau', 'converted' => 'Transformé en demande', 'ignored' => 'Ignoré'][$e->status] ?? $e->status],
            'client' => $e->client ? ['id' => $e->client->id, 'name' => $e->client->name] : null,
            'client_request_id' => $e->client_request_id,
            'attachments' => collect($e->attachments ?? [])->map(fn ($a, $i) => ['index' => $i, 'name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size']])->values(),
            'handler' => $full && $e->relationLoaded('handler') ? AttachmentResources::author($e->handler) : null,
            'handled_at' => $e->handled_at?->toIso8601String(),
        ];
    }
}
