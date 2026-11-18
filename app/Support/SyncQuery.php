<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Synchronisation incrémentale pour l'app mobile (TODO §5).
 * Avec `updated_since` (date ISO 8601), une liste ne renvoie que les fiches modifiées depuis,
 * y compris les fiches supprimées (`deleted_at` rempli) pour que le téléphone les retire.
 * La réponse donne `meta.server_time`, à renvoyer comme `updated_since` à la synchro suivante.
 */
final class SyncQuery
{
    public static function isSync(Request $request): bool
    {
        return $request->filled('updated_since');
    }

    public static function apply(Builder $query, Request $request): Builder
    {
        if (! self::isSync($request)) {
            return $query;
        }

        $since = Carbon::parse((string) $request->string('updated_since'));

        return $query->withTrashed()
            ->where($query->getModel()->getTable().'.updated_at', '>', $since)
            ->reorder()
            ->orderBy($query->getModel()->getTable().'.updated_at')
            ->orderBy($query->getModel()->getTable().'.id');
    }

    /** @return array{meta: array{server_time: string}} */
    public static function meta(): array
    {
        return ['meta' => ['server_time' => now()->toIso8601String()]];
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        $max = self::isSync($request) ? 500 : 100;

        return max(1, min((int) $request->integer('per_page', $default), $max));
    }
}
