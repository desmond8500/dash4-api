<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanIconResource;
use App\Models\PlanIcon;
use App\Support\PlanIconLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bibliothèque d'icônes des plans : lisible par qui voit un chantier ou le stock, gérée par qui modifie
 * le stock ou crée des chantiers. Une icône est un symbole intégré (colorable) ou une image (PNG, JPEG, WebP, SVG).
 */
class PlanIconController extends Controller
{
    private const VIEW = ['sites.view', 'sites.view.own', 'stock.view'];

    private const MANAGE = ['stock.update', 'sites.create'];

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(self::VIEW), 403, "Vous n'avez pas le droit de voir les icônes des plans.");

        return PlanIconResource::collection(PlanIcon::query()->withCount('articles')->orderBy('position')->orderBy('category')->orderBy('name')->get());
    }

    /** POST — `symbol` (intégré) ou `file` (image), avec `name`, `category`, `color`. */
    public function store(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $this->validated($request, true);
        abort_unless(isset($data['symbol']) || $request->hasFile('file'), 422, 'Choisissez un symbole ou envoyez une image.');
        $icon = new PlanIcon(array_diff_key($data, ['file' => true]));
        $icon->created_by = $request->user()->id;
        if ($request->hasFile('file')) {
            $this->storeFile($icon, $request->file('file'));
            $icon->symbol = null;
        }
        $icon->save();

        return (new PlanIconResource($icon->loadCount('articles')))->response()->setStatusCode(201);
    }

    /** POST /plan-icons/{id} (multipart pour remplacer l'image) ou PATCH. */
    public function update(Request $request, PlanIcon $planIcon): PlanIconResource
    {
        $this->manage($request);
        $data = $this->validated($request, false);
        $planIcon->fill(array_diff_key($data, ['file' => true]));
        if ($request->hasFile('file')) {
            $old = $planIcon->path;
            $this->storeFile($planIcon, $request->file('file'));
            $planIcon->symbol = null;
            if ($old) {
                Storage::disk(PlanIcon::DISK)->delete($old);
            }
        } elseif (! empty($data['symbol']) && $planIcon->path) {
            Storage::disk(PlanIcon::DISK)->delete($planIcon->path);
            $planIcon->forceFill(['path' => null, 'mime' => null]);
        }
        $planIcon->save();

        return new PlanIconResource($planIcon->loadCount('articles'));
    }

    public function destroy(Request $request, PlanIcon $planIcon): JsonResponse
    {
        $this->manage($request);
        $planIcon->delete();

        return response()->json(['message' => 'Icône supprimée : les articles et repères qui l\'utilisaient reprennent l\'icône par défaut.']);
    }

    /** POST /plan-icons/install — ajoute la bibliothèque standard (`reset=1` : remet les icônes standard à neuf). */
    public function install(Request $request): JsonResponse
    {
        $this->manage($request);
        $r = PlanIconLibrary::install($request->boolean('reset'), $request->user()->id);

        return response()->json([
            'data' => $r,
            'message' => $r['created'] || $r['updated']
                ? "Bibliothèque standard : {$r['created']} icône(s) ajoutée(s)".($r['updated'] ? ", {$r['updated']} remise(s) à neuf" : '').'.'
                : 'La bibliothèque standard est déjà installée.',
        ]);
    }

    public function file(Request $request, PlanIcon $planIcon): StreamedResponse
    {
        abort_unless($request->user()->canAny(self::VIEW), 403);
        abort_unless($planIcon->path && Storage::disk(PlanIcon::DISK)->exists($planIcon->path), 404, 'Image introuvable.');

        return Storage::disk(PlanIcon::DISK)->response($planIcon->path, null, [
            'Content-Type' => $planIcon->mime,
            'Cache-Control' => 'private, max-age=86400',
            // Une image SVG ne doit jamais exécuter de script, même ouverte seule
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->canAny(self::MANAGE), 403, "Vous n'avez pas le droit de gérer les icônes des plans.");
    }

    private function storeFile(PlanIcon $icon, UploadedFile $file): void
    {
        $mime = $file->getMimeType() ?? '';
        if (str_contains($mime, 'svg') || strtolower($file->getClientOriginalExtension()) === 'svg') {
            $svg = (string) file_get_contents($file->getRealPath());
            // Pas de script ni de contenu externe dans une icône SVG
            abort_if(preg_match('/<\s*(script|foreignObject|iframe|embed|object)\b|\bon\w+\s*=|javascript:|<!ENTITY/i', $svg) === 1, 422, "Cette image SVG contient du code : envoyez une image PNG ou un SVG simple.");
            $mime = 'image/svg+xml';
        }
        $icon->path = $file->store('plan-icons', PlanIcon::DISK);
        $icon->mime = $mime;
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'symbol' => ['sometimes', 'nullable', Rule::in(PlanIcon::SYMBOLS)],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'code' => ['sometimes', 'nullable', 'string', 'max:6'],
            'text_color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'file' => ['sometimes', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp,svg'],
        ], ['file.max' => "L'icône ne doit pas dépasser 1 Mo.", 'color.regex' => 'Couleur au format #rrggbb.', 'text_color.regex' => 'Couleur au format #rrggbb.', 'secondary_color.regex' => 'Couleur au format #rrggbb.'], ['name' => 'nom', 'category' => 'catégorie', 'symbol' => 'symbole', 'file' => 'image', 'code' => 'code', 'text_color' => 'couleur du texte', 'secondary_color' => 'seconde couleur']);
    }
}
