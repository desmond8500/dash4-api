<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Brand;
use App\Models\PracticalToolLink;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outils pratiques (calculs, conversions) et leurs liens avec des marques, fournisseurs ou articles.
 * Les outils sont ouverts à tous les comptes (aucun prix) ; lier ou délier demande `stock.update`.
 */
class PracticalToolController extends Controller
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = ['articles' => Article::class, 'brands' => Brand::class, 'providers' => Provider::class];

    /** GET /api/v1/practical-tools — les outils et leurs liens. */
    public function index(): JsonResponse
    {
        $links = PracticalToolLink::query()->with('linkable')->orderBy('id')->get()
            ->filter(fn (PracticalToolLink $l) => $l->linkable !== null)
            ->groupBy('tool');

        $tools = collect(config('dash4.practical_tools'))->map(fn (string $name, string $slug) => [
            'slug' => $slug,
            'name' => $name,
            'links' => ($links[$slug] ?? collect())->map(fn ($l) => $this->link($l))->values(),
        ])->values();

        return response()->json(['data' => $tools]);
    }

    /** GET /api/v1/practical-tools/for/{type}/{id} — codes des outils liés à une marque, un fournisseur ou un article. */
    public function forRecord(string $type, int $id): JsonResponse
    {
        $record = $this->record($type, $id);
        $links = PracticalToolLink::query()->whereMorphedTo('linkable', $record)->orderBy('id')->get()
            ->filter(fn ($l) => array_key_exists($l->tool, config('dash4.practical_tools')));

        return response()->json(['data' => $links->map(fn ($l) => ['id' => $l->id, 'tool' => $l->tool, 'name' => config("dash4.practical_tools.{$l->tool}")])->values()]);
    }

    /** POST /api/v1/practical-tools/{tool}/links — `type` (articles, brands, providers) et `id`. Déjà lié : rien ne change. */
    public function store(Request $request, string $tool): JsonResponse
    {
        abort_unless(array_key_exists($tool, config('dash4.practical_tools')), 404, 'Outil inconnu.');
        abort_unless($request->user()->can('stock.update'), 403, "Vous n'avez pas le droit de faire cette action.");
        $data = $request->validate([
            'type' => ['required', 'in:articles,brands,providers'],
            'id' => ['required', 'integer'],
        ], [], ['type' => 'type de fiche', 'id' => 'fiche']);

        $record = $this->record($data['type'], $data['id']);
        $link = PracticalToolLink::query()->where('tool', $tool)->whereMorphedTo('linkable', $record)->first();
        $status = 200;
        if (! $link) {
            $link = new PracticalToolLink(['tool' => $tool]);
            $link->linkable()->associate($record);
            $link->created_by = $request->user()->id;
            $link->save();
            $status = 201;
        }

        return response()->json(['data' => $this->link($link->load('linkable'))], $status);
    }

    /** DELETE /api/v1/practical-tool-links/{link} */
    public function destroy(Request $request, PracticalToolLink $link): JsonResponse
    {
        abort_unless($request->user()->can('stock.update'), 403, "Vous n'avez pas le droit de faire cette action.");
        $link->delete();

        return response()->json(['message' => 'Lien retiré.']);
    }

    private function record(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404, "Type de fiche inconnu : {$type}.");

        return $class::query()->findOrFail($id);
    }

    /** @return array{id: int, type: string, target: array{id: int, name: string, reference: ?string}} */
    private function link(PracticalToolLink $link): array
    {
        $record = $link->linkable;
        $type = array_search($record::class, self::TYPES, true);

        return [
            'id' => $link->id,
            'type' => $type,
            'target' => [
                'id' => $record->id,
                'name' => $record instanceof Article ? $record->designation : $record->name,
                'reference' => $record instanceof Article ? $record->reference : null,
            ],
        ];
    }
}
