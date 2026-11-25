<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteForm;
use App\Http\Resources\QuoteResource;
use App\Models\Document;
use App\Models\Quote;
use App\Support\DocumentNumber;
use App\Support\QuoteEditing;
use App\Support\QuotePdf;
use App\Support\QuoteQuantities;
use App\Support\SyncQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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

        // Favori, notes internes, proposition technique et devis neutre (présentation du PDF) : toujours possibles ;
        // le reste seulement sur un brouillon ou un devis envoyé
        if (array_diff(array_keys($data), ['is_favorite', 'notes', 'proposal_options', 'is_neutral']) !== []) {
            QuoteEditing::ensureEditable($quote);
        }
        if (isset($data['proposal_options'])) {
            $data['proposal_options'] = array_replace($quote->proposalOptions(), $data['proposal_options']);
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

    /**
     * GET /api/v1/quotes/{id}/pdf — téléchargement (inline=1 : affichage dans le navigateur).
     * `neutral=1` / `neutral=0` : version neutre ou non pour ce téléchargement, sans changer le réglage du devis.
     */
    public function pdf(Request $request, Quote $quote): Response
    {
        $this->authorize('export', $quote);
        $pdf = QuotePdf::quote($quote, $request->has('neutral') ? $request->boolean('neutral') : null);
        $filename = $quote->number.'.pdf';

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * GET /api/v1/quotes/{id}/quantities — quantitatif sans prix et sans les forfaits (main-d'œuvre) :
     * toutes les sections visibles, ou `section_id` pour une seule. `format=pdf` (défaut) ou `csv` ;
     * `inline=1` et `neutral=0|1` comme pour le PDF du devis. Aucun prix : ouvert à qui voit le devis.
     */
    public function quantities(Request $request, Quote $quote): Response
    {
        $this->authorize('view', $quote);
        $data = $request->validate([
            'format' => ['nullable', 'in:pdf,csv'],
            'section_id' => ['nullable', 'integer'],
        ], [], ['format' => 'format', 'section_id' => 'section']);

        $section = null;
        if (isset($data['section_id'])) {
            $section = $quote->sections()->whereKey($data['section_id'])->first();
            abort_unless($section, 404, 'Section introuvable dans ce devis.');
        }
        $quantities = new QuoteQuantities($quote, $section);
        $filename = $quantities->filename();

        if (($data['format'] ?? 'pdf') === 'csv') {
            [$headers, $rows] = $quantities->table();

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers, ';', '"', '');
                foreach ($rows as $row) {
                    fputcsv($out, $row, ';', '"', '');
                }
                fclose($out);
            }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $pdf = QuotePdf::quantities($quantities, $request->has('neutral') ? $request->boolean('neutral') : null);

        return $request->boolean('inline') ? $pdf->stream($filename.'.pdf') : $pdf->download($filename.'.pdf');
    }

    /** GET /api/v1/quotes/{id}/proposal-pdf — proposition technique. */
    public function proposalPdf(Request $request, Quote $quote): Response
    {
        $this->authorize('view', $quote);
        $options = $quote->proposalOptions();
        if ($options['show_prices']) {
            $this->authorize('export', $quote);
        }
        $pdf = QuotePdf::proposal($quote);
        $filename = 'Proposition technique '.$quote->number.'.pdf';

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
}
