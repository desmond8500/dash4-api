<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Numérotation des documents (DEV-2026-0001, FAC-2026-0001…).
 * Un compteur par type et par année : la numérotation repart à 1 chaque année.
 * Réservation sous verrou, sans trou ni doublon même avec plusieurs utilisateurs.
 */
final class DocumentNumber
{
    /** Réserve et renvoie le prochain numéro. */
    public static function next(string $type, ?Carbon $date = null): string
    {
        $date ??= now();
        self::ensureType($type);

        $number = DB::transaction(function () use ($type, $date) {
            $year = (int) $date->year;
            $row = DB::table('document_sequences')->where(['type' => $type, 'year' => $year])->lockForUpdate()->first();

            if ($row === null) {
                DB::table('document_sequences')->insert([
                    'type' => $type, 'year' => $year, 'last_number' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('document_sequences')->where('id', $row->id)->update([
                'last_number' => $row->last_number + 1, 'updated_at' => now(),
            ]);

            return $row->last_number + 1;
        });

        return self::format((string) Settings::get('numbering')[$type], $number, $date);
    }

    /** Numéro que recevra le prochain document, sans le réserver (aperçu dans les paramètres). */
    public static function preview(string $type, ?string $format = null, ?Carbon $date = null): string
    {
        $date ??= now();
        self::ensureType($type);
        $last = (int) DB::table('document_sequences')->where(['type' => $type, 'year' => $date->year])->value('last_number');

        return self::format($format ?? (string) Settings::get('numbering')[$type], $last + 1, $date);
    }

    public static function format(string $format, int $number, Carbon $date): string
    {
        $result = preg_replace_callback('/\{(#+)\}/', fn ($m) => str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT), $format);

        return strtr($result, [
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
        ]);
    }

    private static function ensureType(string $type): void
    {
        if (! array_key_exists($type, Settings::DOCUMENT_TYPES)) {
            throw new InvalidArgumentException("Type de document inconnu : {$type}");
        }
    }
}
