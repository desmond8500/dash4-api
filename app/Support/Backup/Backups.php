<?php

namespace App\Support\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegardes de Dash4, sans outil externe (hébergement mutualisé) :
 * - la base en SQL compressé (`dash4-base-AAAAMMJJ-HHMMSS.sql.gz`), rejouable avec `mysql` ;
 * - en option les fichiers (documents, photos, logos) en ZIP (`dash4-fichiers-….zip`).
 * Rangées dans storage/app/private/backups ; les plus anciennes sont supprimées (BACKUP_KEEP_DAYS).
 * Une copie hors du serveur reste nécessaire (règle 3-2-1) : la télécharger depuis la page Sauvegardes.
 */
final class Backups
{
    public const DIR = 'backups';

    private const PATTERN = '/^dash4-(base|fichiers)-\d{8}-\d{6}\.(sql\.gz|zip)$/';

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    /** @return array{database: string, files: string|null} noms des fichiers créés */
    public static function run(bool $withFiles = false): array
    {
        $stamp = now()->format('Ymd-His');
        $database = self::dumpDatabase("dash4-base-{$stamp}.sql.gz");
        $files = $withFiles ? self::zipFiles("dash4-fichiers-{$stamp}.zip") : null;
        self::prune((int) config('dash4.backups.keep_days', 14));

        return ['database' => $database, 'files' => $files];
    }

    /** @return list<array{name: string, kind: string, size: int, created_at: string}> la plus récente d'abord */
    public static function list(): array
    {
        $disk = self::disk();

        return collect($disk->files(self::DIR))
            ->map(fn ($path) => basename($path))
            ->filter(fn ($name) => self::isValidName($name))
            ->map(fn ($name) => [
                'name' => $name,
                'kind' => str_contains($name, '-base-') ? 'database' : 'files',
                'size' => $disk->size(self::DIR.'/'.$name),
                'created_at' => Carbon::createFromFormat('Ymd-His', substr(preg_replace('/^dash4-(base|fichiers)-/', '', $name), 0, 15))->toIso8601String(),
            ])
            ->sortByDesc('name')->values()->all();
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public static function path(string $name): string
    {
        return self::DIR.'/'.$name;
    }

    /** Supprime les sauvegardes plus anciennes que `$days` jours (la plus récente de chaque sorte est toujours gardée). */
    public static function prune(int $days): int
    {
        $limit = now()->subDays(max(1, $days));
        $deleted = 0;
        foreach (['database', 'files'] as $kind) {
            $items = array_values(array_filter(self::list(), fn ($b) => $b['kind'] === $kind));
            foreach (array_slice($items, 1) as $b) {
                if (Carbon::parse($b['created_at'])->lt($limit)) {
                    self::disk()->delete(self::path($b['name']));
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private static function dumpDatabase(string $name): string
    {
        $disk = self::disk();
        $disk->makeDirectory(self::DIR);
        $path = $disk->path(self::path($name));
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Impossible d'écrire la sauvegarde {$name}.");
        }

        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $driver = $connection->getDriverName();
            $mysql = in_array($driver, ['mysql', 'mariadb'], true);
            $q = fn (string $id) => $mysql ? '`'.str_replace('`', '``', $id).'`' : '"'.str_replace('"', '""', $id).'"';

            gzwrite($gz, '-- Sauvegarde Dash4 du '.now()->toDateTimeString()."\n-- Restauration : gunzip < {$name} | mysql -h HOTE -u UTILISATEUR -p BASE\n\n");
            if ($mysql) {
                gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            }

            foreach (self::tables($driver) as $table) {
                $create = $mysql
                    ? (array) DB::selectOne('SHOW CREATE TABLE '.$q($table))
                    : ['sql' => DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql')];
                $createSql = $mysql ? ($create['Create Table'] ?? array_values($create)[1]) : $create['sql'];
                gzwrite($gz, '-- Table '.$table."\nDROP TABLE IF EXISTS ".$q($table).";\n{$createSql};\n");

                $batch = [];
                foreach (DB::table($table)->cursor() as $row) {
                    $values = array_map(fn ($v) => match (true) {
                        $v === null => 'NULL',
                        is_bool($v) => $v ? '1' : '0',
                        is_int($v), is_float($v) => (string) $v,
                        default => $pdo->quote((string) $v),
                    }, (array) $row);
                    $batch[] = '('.implode(',', $values).')';
                    if (count($batch) === 200) {
                        self::writeInsert($gz, $q($table), array_keys((array) $row), $batch, $q);
                        $batch = [];
                    }
                }
                if ($batch) {
                    self::writeInsert($gz, $q($table), array_keys((array) $row), $batch, $q);
                }
                gzwrite($gz, "\n");
            }
            if ($mysql) {
                gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            }
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($path);
            throw $e;
        }
        gzclose($gz);

        return $name;
    }

    private static function writeInsert($gz, string $table, array $columns, array $rows, \Closure $q): void
    {
        gzwrite($gz, "INSERT INTO {$table} (".implode(',', array_map($q, $columns)).") VALUES\n".implode(",\n", $rows).";\n");
    }

    /** @return list<string> */
    private static function tables(string $driver): array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        }

        return DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->orderBy('name')->pluck('name')->all();
    }

    /** Documents privés et fichiers publics (logos, photos), sans les sauvegardes elles-mêmes. */
    private static function zipFiles(string $name): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('L\'extension PHP zip est absente : sauvegarde des fichiers impossible.');
        }
        $disk = self::disk();
        $zip = new ZipArchive;
        if ($zip->open($disk->path(self::path($name)), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossible d'écrire la sauvegarde {$name}.");
        }
        foreach (['local' => 'prives', 'public' => 'publics'] as $diskName => $folder) {
            $source = Storage::disk($diskName);
            foreach ($source->allFiles() as $file) {
                if ($diskName === 'local' && str_starts_with($file, self::DIR.'/')) {
                    continue;
                }
                $zip->addFile($source->path($file), "{$folder}/{$file}");
            }
        }
        $zip->close();

        return $name;
    }
}
