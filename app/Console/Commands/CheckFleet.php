<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\FleetNotification;
use App\Support\Fleet;
use Illuminate\Console\Command;

/**
 * Chaque matin : prévient les gestionnaires du parc (et le conducteur attitré) des papiers qui expirent
 * dans 30 jours, puis dans 7 jours, puis expirés, et des entretiens proches ou en retard (une alerte « proche », puis une « en retard »).
 */
class CheckFleet extends Command
{
    protected $signature = 'fleet:check';

    protected $description = 'Papiers des véhicules qui expirent et entretiens à prévoir';

    public function handle(): int
    {
        $today = today();
        $managers = User::permission('vehicles.update')->where('is_active', true)->get();
        $sent = 0;

        Vehicle::query()->whereNotIn('status', ['sold'])->with(['papers', 'maintenances', 'driver'])->get()
            ->each(function (Vehicle $vehicle) use ($today, $managers, &$sent) {
                $recipients = collect($managers->all())->when($vehicle->driver && $vehicle->driver->is_active, fn ($c) => $c->push($vehicle->driver))->unique('id');
                $notify = function (string $event, string $subject, ?string $date) use ($recipients, $vehicle, &$sent) {
                    $recipients->each->notify(new FleetNotification($vehicle, $event, $subject, $date));
                    $sent++;
                };

                foreach (Fleet::currentPapers($vehicle->papers) as $paper) {
                    if ($paper->expires_on === null) {
                        continue;
                    }
                    $days = $today->diffInDays($paper->expires_on, false);
                    $level = $days < 0 ? 3 : ($days <= 7 ? 2 : ($days <= Fleet::PAPER_WARNING_DAYS ? 1 : 0));
                    if ($level > $paper->alert_level) {
                        $notify($level === 3 ? 'paper_expired' : 'paper_soon', Fleet::paperLabel($paper), $paper->expires_on->toDateString());
                        $paper->forceFill(['alert_level' => $level])->save();
                    }
                }

                foreach (Fleet::upcoming($vehicle, $today) as $next) {
                    if ($next['state'] === 'ok') {
                        continue;
                    }
                    $maintenance = $vehicle->maintenances->firstWhere('id', $next['maintenance_id']);
                    $rank = ['soon' => 1, 'late' => 2];
                    if ($rank[$next['state']] > ($rank[$maintenance->alert_state] ?? 0)) {
                        $notify($next['state'] === 'late' ? 'maintenance_late' : 'maintenance_soon', $next['label'], $next['next_on']);
                        $maintenance->forceFill(['alert_state' => $next['state']])->save();
                    }
                }
            });

        $this->info("{$sent} alerte(s) du parc automobile envoyée(s).");

        return self::SUCCESS;
    }
}
