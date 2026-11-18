<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Support\Attachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents d'une fiche : /api/v1/{type}/{id}/documents (liste, envoi), puis
 * /api/v1/documents/{document}/download et DELETE /api/v1/documents/{document}.
 * Fichiers privés : jamais d'adresse publique.
 */
class DocumentController extends Controller
{
    /** Formats acceptés : documents, images, plans, archives. Pas d'exécutables, ni de HTML ou de SVG. */
    public const EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'csv', 'txt',
        'dwg', 'dxf', 'zip',
    ];

    public const MAX_KB = 20480;

    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeView($request->user(), $parent);

        return DocumentResource::collection($parent->documents()->with('creator:id,firstname,lastname')->get());
    }

    /** Envoi multipart : `file`, et `name` facultatif (nom affiché). */
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $parent = Attachable::find($type, $id);
        Attachable::authorizeAdd($request->user(), $parent);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
            'name' => ['nullable', 'string', 'max:255'],
        ], [], ['file' => 'fichier', 'name' => 'nom']);

        $file = $data['file'];
        $document = $parent->documents()->make([
            'name' => $data['name'] ?? $file->getClientOriginalName(),
            'path' => $file->store("documents/{$type}/{$id}", Document::DISK),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $document->created_by = $request->user()->id;
        $document->save();
        $parent->touch();

        return (new DocumentResource($document->load('creator:id,firstname,lastname')))->response()->setStatusCode(201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $parent = $document->documentable()->withTrashed()->firstOrFail();
        Attachable::authorizeView($request->user(), $parent);

        abort_unless(Storage::disk(Document::DISK)->exists($document->path), 404, 'Fichier introuvable.');

        return Storage::disk(Document::DISK)->download($document->path, $document->name, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $parent = $document->documentable()->withTrashed()->firstOrFail();
        Attachable::authorizeChange($request->user(), $document, $parent);

        $document->delete();
        $parent->touch();

        return response()->json(['message' => 'Document supprimé.']);
    }
}
