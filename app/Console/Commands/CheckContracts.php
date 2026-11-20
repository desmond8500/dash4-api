<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractNotification;
use Illuminate\Console\Command;

/**
 * Chaque jour : prévient de l'échéance des contrats (au début du préavis), reconduit ceux en tacite
 * reconduction arrivés à échéance (même durée), termine les autres, et signale les contrats à facturer.
 */
class CheckContracts extends Command
{
    protected $signature = 'contracts:check';

    protected $description = 'Échéances, reconductions et facturation des contrats';

    public function handle(): int
    {
        $today = today();
        $managers = fn (string $permission) => User::permission($permission)->where('is_active', true)->get();

        // Échéance proche : une seule alerte par échéance
        Contract::query()->where('status', ContractStatus::Active->value)->whereNotNull('end_date')->whereNull('end_notified_at')
            ->whereDate('end_date', '>=', $today)->get()
            ->filter(fn (Contract $c) => $c->end_date->lte($today->copy()->addDays($c->notice_days)))
            ->each(function (Contract $c) use ($managers) {
                $managers('contracts.update')->each->notify(new ContractNotification($c, 'ending'));
                $c->forceFill(['end_notified_at' => now()])->save();
            });

        // Échéance passée : reconduction tacite (même durée) ou fin du contrat
        Contract::query()->where('status', ContractStatus::Active->value)->whereNotNull('end_date')->whereDate('end_date', '<', $today)->get()
            ->each(function (Contract $c) {
                if ($c->tacit_renewal) {
                    $months = max(1, (int) round($c->start_date->diffInMonths($c->end_date->copy()->addDay())));
                    $c->forceFill(['end_date' => $c->end_date->copy()->addMonthsNoOverflow($months), 'end_notified_at' => null])->save();
                    activity('contracts')->performedOn($c)->log("Contrat reconduit jusqu'au {$c->end_date->format('d/m/Y')}");
                } else {
                    $c->forceFill(['status' => ContractStatus::Ended->value])->save();
                }
            });

        // Visites de maintenance des 30 prochains jours : une tâche au planning pour chacune
        $visits = \App\Support\ContractVisits::plan(30);
        if ($visits > 0) {
            $this->info("{$visits} visite(s) de maintenance ajoutée(s) au planning.");
        }

        // À facturer : un rappel par jour aux personnes qui facturent
        $due = Contract::query()->where('status', ContractStatus::Active->value)->whereDate('next_invoice_date', '<=', $today)->count();
        if ($due > 0) {
            $managers('invoices.create')->each->notify(new ContractNotification(null, 'due', $due));
        }
        $this->info("{$due} contrat(s) à facturer.");

        return self::SUCCESS;
    }
}
