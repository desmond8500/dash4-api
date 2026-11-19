<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\TechShare;
use Illuminate\Console\Command;

/** Efface le contenu des informations temporaires arrivées à expiration (date passée, ou tâche terminée). */
class ExpireTechShares extends Command
{
    protected $signature = 'tech-shares:expire';

    protected $description = 'Efface les informations temporaires expirées';

    public function handle(): int
    {
        $count = 0;
        TechShare::query()->whereNull('expired_at')
            ->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->orWhere(fn ($w) => $w->where('expires_with_task', true)->whereHas('task', fn ($t) => $t->whereIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]))))
            ->each(function (TechShare $share) use (&$count) {
                $share->expire();
                $count++;
            });
        $this->info("{$count} information(s) expirée(s).");

        return self::SUCCESS;
    }
}
