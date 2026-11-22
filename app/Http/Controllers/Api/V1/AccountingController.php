<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Bilan simple et journal des entrées / sorties (accounting.view). Période par défaut : l'année en cours. */
class AccountingController extends Controller
{
    /** GET /api/v1/accounting/summary — `from`, `to`, `project_id`. */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $project] = $this->period($request);

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            ...Ledger::summary(Ledger::entries($from, $to, $project)),
        ]]);
    }

    /** GET /api/v1/accounting/ledger — `from`, `to`, `project_id`, `direction`, `source`, `search`, `page`, `per_page`. */
    public function ledger(Request $request): JsonResponse
    {
        [$from, $to, $project] = $this->period($request);
        $entries = Ledger::entries($from, $to, $project)
            ->when($request->filled('direction'), fn ($c) => $c->where('direction', (string) $request->string('direction')))
            ->when($request->filled('source'), fn ($c) => $c->filter(fn ($e) => $e['source']['type'] === (string) $request->string('source')))
            ->when($request->filled('search'), function ($c) use ($request) {
                // Chaque mot dans le libellé, la catégorie ou le projet (accents et majuscules ignorés)
                $fold = fn (?string $v) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $v));
                $words = array_filter(preg_split('/\s+/', $fold($request->string('search')->toString())));

                return $c->filter(function ($e) use ($fold, $words) {
                    $hay = $fold(implode(' ', [$e['label'] ?? '', $e['category'] ?? '', $e['project']['name'] ?? '']));

                    return collect($words)->every(fn ($w) => str_contains($hay, $w));
                });
            })
            ->values();
        $perPage = max(1, min(200, $request->integer('per_page', 50)));
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $entries->forPage($page, $perPage)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($entries->count() / $perPage)), 'per_page' => $perPage, 'total' => $entries->count()],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?int} */
    private function period(Request $request): array
    {
        abort_unless($request->user()->can('accounting.view'), 403);
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'project_id' => ['sometimes', 'integer'],
        ], [], ['from' => 'début', 'to' => 'fin']);

        return [
            $request->filled('from') ? Carbon::parse($request->string('from')) : now()->startOfYear(),
            $request->filled('to') ? Carbon::parse($request->string('to')) : now()->endOfYear(),
            $request->filled('project_id') ? $request->integer('project_id') : null,
        ];
    }
}
