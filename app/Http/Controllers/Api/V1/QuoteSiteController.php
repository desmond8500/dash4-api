<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Equipment;
use App\Models\InstalledSystem;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Appareils du chantier créés depuis les lignes d'un devis.
 *
 * POST /api/v1/quotes/{quote}/site-equipment
 * - `groups` : `[{ installed_system_id | system_name, line_ids: [...] }]` — un système existant du projet,
 *   ou un nouveau système nommé (souvent le titre de la section) ;
 * - `split` : un appareil par unité (pour noter chaque n° de série), sinon un appareil avec la quantité.
 *
 * Seul le reste de chaque ligne est créé (quantité du devis moins les appareils déjà créés depuis cette ligne) :
 * relancer, ou rejouer depuis la file d'attente du mobile, ne crée pas de doublon.
 */
class QuoteSiteController extends Controller
{
    /** Au-delà, la saisie par unité devient illisible : mieux vaut un appareil avec sa quantité. */
    public const MAX_UNITS = 500;

    public function store(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);
        $project = $quote->project;
        abort_unless($project, 422, "Rattachez d'abord le devis à un projet.");
        $this->authorize('manageSite', $project);

        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'split' => ['sometimes', 'boolean'],
            'groups' => ['required', 'array', 'min:1', 'max:50'],
            'groups.*.installed_system_id' => ['nullable', 'integer', Rule::exists('installed_systems', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'groups.*.system_name' => ['nullable', 'required_without:groups.*.installed_system_id', 'string', 'max:255'],
            'groups.*.line_ids' => ['required', 'array', 'min:1'],
            'groups.*.line_ids.*' => ['integer'],
        ], [
            'groups.required' => 'Choisissez au moins une ligne du devis.',
            'groups.*.system_name.required_without' => 'Choisissez le système du chantier.',
            'groups.*.installed_system_id.exists' => "Ce système n'est pas sur le chantier du projet.",
        ], ['groups.*.system_name' => 'système']);

        $creatingSystem = collect($data['groups'])->contains(fn ($g) => empty($g['installed_system_id']));
        abort_if($creatingSystem && ! $request->user()->can('sites.create'), 403, "Vous n'avez pas le droit d'ajouter un système au chantier.");

        $lines = QuoteLine::query()
            ->whereHas('section', fn ($s) => $s->where('quote_id', $quote->id))
            ->with('article:id,designation,reference')
            ->get()
            ->keyBy('id');
        $seen = [];
        foreach ($data['groups'] as $group) {
            foreach ($group['line_ids'] as $id) {
                abort_unless($lines->has($id), 422, "Cette ligne n'est pas dans le devis.");
                abort_if(isset($seen[$id]), 422, 'Une ligne ne peut aller que dans un seul système.');
                $seen[$id] = true;
            }
        }

        $done = $this->alreadyCreated(array_keys($seen));
        $split = (bool) ($data['split'] ?? false);
        if ($split) {
            $units = collect(array_keys($seen))->sum(fn ($id) => max(0, (int) ceil((float) $lines[$id]->quantity) - ($done[$id] ?? 0)));
            abort_if($units > self::MAX_UNITS, 422, 'Plus de '.self::MAX_UNITS.' appareils : créez-les avec leur quantité plutôt qu’un par unité.');
        }

        $created = DB::transaction(function () use ($data, $project, $lines, $done, $split) {
            $created = 0;
            foreach ($data['groups'] as $group) {
                $system = ! empty($group['installed_system_id'])
                    ? InstalledSystem::findOrFail($group['installed_system_id'])
                    : $this->systemNamed($project, trim($group['system_name']));
                $position = (int) $system->equipment()->max('position');
                foreach ($group['line_ids'] as $id) {
                    /** @var QuoteLine $line */
                    $line = $lines[$id];
                    $remaining = max(0, (int) ceil((float) $line->quantity) - ($done[$id] ?? 0));
                    if ($remaining === 0) {
                        continue;
                    }
                    $fields = [
                        'quote_line_id' => $line->id,
                        'article_id' => $line->article_id,
                        'name' => trim((string) $line->designation) ?: ($line->article?->designation ?? 'Équipement'),
                        'type' => $line->category?->label(),
                        'model' => $line->reference ?: $line->article?->reference,
                        'description' => $line->comment,
                        'status' => 'planned',
                    ];
                    foreach ($split ? array_fill(0, $remaining, 1) : [$remaining] as $quantity) {
                        $system->equipment()->create($fields + ['quantity' => $quantity, 'position' => ++$position]);
                        $created++;
                    }
                }
            }

            return $created;
        });

        return (new SiteController)->tree($project->refresh())
            ->additional(['meta' => ['created' => $created]])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /** Quantités déjà créées depuis chaque ligne (appareils non supprimés). */
    private function alreadyCreated(array $lineIds): array
    {
        return Equipment::query()
            ->whereIn('quote_line_id', $lineIds)
            ->selectRaw('quote_line_id, SUM(quantity) as total')
            ->groupBy('quote_line_id')
            ->pluck('total', 'quote_line_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Système du chantier portant ce nom (sans tenir compte de la casse), sinon un nouveau. */
    private function systemNamed($project, string $name): InstalledSystem
    {
        $existing = $project->installedSystems()->get()->first(fn ($s) => mb_strtolower(trim($s->name)) === mb_strtolower($name));

        return $existing ?? $project->installedSystems()->create([
            'name' => $name,
            'position' => (int) $project->installedSystems()->max('position') + 1,
        ]);
    }
}
