<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Support\Backup\Backups;
use Illuminate\Console\Command;
use Throwable;

/** Sauvegarde de la base (et des fichiers avec --files). En cas d'échec, les administrateurs sont prévenus. */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--files : Sauvegarder aussi les documents, photos et logos (ZIP)}';

    protected $description = 'Sauvegarde la base Dash4 (et les fichiers) dans storage/app/private/backups';

    public function handle(): int
    {
        try {
            $result = Backups::run((bool) $this->option('files'));
        } catch (Throwable $e) {
            report($e);
            User::role('admin')->where('is_active', true)->get()->each->notify(new BackupFailedNotification($e->getMessage()));
            $this->error('Sauvegarde impossible : '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sauvegarde : '.implode(', ', array_filter($result)));

        return self::SUCCESS;
    }
}
