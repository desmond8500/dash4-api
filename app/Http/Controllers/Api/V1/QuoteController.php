<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteForm;
use App\Http\Resources\QuoteResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\Quote;
use App\Support\DocumentNumber;
use App\Support\QuoteEditing;
use App\Support\Settings;
use App\Support\SyncQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

class QuoteController extends Controller
{
    private const LIST_RELATIONS = ['client:id,name', 'project:id,name,manager_id'];

    /**
     * GET /api/v1/quotes — filtres : search (numéro, objet, client), status (liste), client_id,
     * project_id, year ; synchro : updated_since. Tri : favoris, puis les plus récents.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Quote::class);

        $query = Quote::query()
            ->visibleTo($request->user())
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('issued_at', $request->integer('year')))
            ->orderByDesc('is_favorite')
            ->latest('issued_at')
            ->orderByDesc('id');

        SyncQuery::apply($query, $request);

        return QuoteResource::collection($query->paginate(SyncQuery::perPage($request)))->additional(SyncQuery::meta());
    }

    /** POST /api/v1/quotes — numéro, taxe, taux, validité (30 jours) et compte bancaire par défaut. */
    public function store(QuoteForm $request): JsonResponse
    {
        $this->authorize('create', Quote::class);

        if ($request->filled('client_uuid') && $existing = Quote::withTrashed()->where('client_uuid', $request->input('client_uuid'))->first()) {
            return (new QuoteResource($existing->load(QuoteEditing::FULL)))->response()->setStatusCode(200);
        }

        $quote = QuoteEditing::createDraft($request->validated(), $request->user());

        return (new QuoteResource($quote->load(QuoteEditing::FULL)))->response()->setStatusCode(201);
    }

    public function show(Quote $quote): QuoteResource
    {
        $this->authorize('view', $quote);

        return new QuoteResource($quote->load(QuoteEditing::FULL));
    }

    public function update(QuoteForm $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        $data = $request->validated();

        // Favori : toujours possible ; le reste seulement sur un brouillon ou un devis envoyé
        if (array_diff(array_keys($data), ['is_favorite']) !== []) {
            QuoteEditing::ensureEditable($quote);
        }

        $quote->update($data);
        if ($quote->wasChanged(['tax_mode', 'discount_rate'])) {
            $quote->recalculate();
        }

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    /** POST /api/v1/quotes/{id}/status — envoyé, accepté, refusé, annulé, rouvert. */
    public function status(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        $status = QuoteStatus::from($request->validate(['status' => ['required', new Enum(QuoteStatus::class)]], [], ['status' => 'statut'])['status']);

        abort_unless(
            in_array($status, QuoteEditing::allowedTransitions($quote->status), true),
            422,
            "Un devis « {$quote->status->label()} » ne peut pas passer à « {$status->label()} ».",
        );
        if ($status->needsValidation() || $quote->status === QuoteStatus::Accepted) {
            $this->authorize('validate', $quote);
        }

        $quote->status = $status;
        match ($status) {
            QuoteStatus::Sent => $quote->sent_at ??= now(),
            QuoteStatus::Accepted, QuoteStatus::Refused => $quote->forceFill(['decided_at' => now(), 'decided_by' => $request->user()->id]),
            QuoteStatus::Draft => $quote->forceFill(['decided_at' => null, 'decided_by' => null]),
            default => null,
        };
        $quote->save();

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    /** POST /api/v1/quotes/{id}/duplicate — nouveau brouillon, nouveau numéro, mêmes sections et lignes. */
    public function duplicate(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);
        $this->authorize('create', Quote::class);

        $copy = DB::transaction(function () use ($quote, $request) {
            $copy = $quote->replicate([
                'number', 'client_uuid', 'status', 'sent_at', 'decided_at', 'decided_by', 'signed_at',
                'signed_document_id', 'legacy_id', 'is_favorite', 'created_by',
            ]);
            $copy->forceFill([
                'number' => DocumentNumber::next('quote'),
                'title' => $quote->title,
                'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'created_by' => $request->user()->id,
            ])->save();

            foreach ($quote->sections()->with('lines')->get() as $section) {
                $newSection = $copy->sections()->create($section->only(['title', 'position', 'is_visible']));
                foreach ($section->lines as $line) {
                    $newSection->lines()->create($line->only([
                        'position', 'article_id', 'designation', 'reference', 'comment', 'category', 'unit', 'quantity', 'unit_cost', 'coefficient',
                    ]));
                }
            }
            $copy->recalculate();

            return $copy;
        });

        return (new QuoteResource($copy->refresh()->load(QuoteEditing::FULL)))->response()->setStatusCode(201);
    }

    /** GET /api/v1/quotes/{id}/pdf — téléchargement (inline=1 : affichage dans le navigateur). */
    public function pdf(Request $request, Quote $quote): Response
    {
        $this->authorize('export', $quote);
        $quote->load(['client.phones', 'client.emails', 'project', 'bankAccount', 'sections' => fn ($q) => $q->where('is_visible', true), 'sections.lines']);
        $company = Company::current();
        $signatureOn = $quote->show_signature && ($company->signatureSettings()['quote'] ?? true);

        $pdf = Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'company' => $company,
            'currency' => Settings::get('currency'),
            'logo' => self::dataUri('public', $company->logo_path),
            'signature' => $signatureOn ? self::dataUri('local', $company->signature_path) : null,
            'stamp' => $signatureOn ? self::dataUri('local', $company->stamp_path) : null,
        ])->setPaper('a4');

        $filename = $quote->number.'.pdf';

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * POST /api/v1/quotes/{id}/signed-copy — copie signée par le client (PDF ou photo).
     * Le devis passe à « Accepté » ; la copie rejoint ses documents.
     */
    public function signedCopy(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('validate', $quote);
        $file = $request->validate(
            ['file' => ['required', 'file', 'max:20480', 'extensions:pdf,jpg,jpeg,png,webp,heic']],
            [],
            ['file' => 'copie signée'],
        )['file'];

        DB::transaction(function () use ($quote, $file, $request) {
            $document = $quote->documents()->make([
                'name' => 'Copie signée '.$quote->number.'.'.strtolower($file->getClientOriginalExtension()),
                'path' => $file->store("documents/quotes/{$quote->id}", Document::DISK),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
            $document->created_by = $request->user()->id;
            $document->save();

            $quote->forceFill(['signed_at' => now(), 'signed_document_id' => $document->id]);
            if ($quote->status !== QuoteStatus::Accepted) {
                $quote->forceFill(['status' => QuoteStatus::Accepted, 'decided_at' => now(), 'decided_by' => $request->user()->id]);
            }
            $quote->save();
        });

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    public function destroy(Quote $quote): JsonResponse
    {
        $this->authorize('delete', $quote);
        $quote->delete();

        return response()->json(['message' => 'Devis supprimé.']);
    }

    /** Image intégrée au PDF (data URI) : rien n'est chargé depuis l'extérieur. */
    private static function dataUri(string $disk, ?string $path): ?string
    {
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }
        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }
}
