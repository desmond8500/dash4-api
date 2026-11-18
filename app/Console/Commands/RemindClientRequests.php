<?php

namespace App\Console\Commands;

use App\Models\ClientRequest;
use App\Models\User;
use App\Notifications\ClientRequestNotification;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Relance des demandes clients (TODO §3), lancée toutes les heures par le planificateur :
 * demandes « Nouvelle » depuis plus de 2 jours, et dates de rappel arrivées.
 * Chaque relance n'est envoyée qu'une fois.
 */
class RemindClientRequests extends Command
{
    protected $signature = 'client-requests:remind';

    protected $description = 'Relance les demandes clients en retard et celles dont la date de rappel est arrivée';

    public function handle(): int
    {
        $overdue = 0;
        ClientRequest::query()->overdue()->whereNull('overdue_notified_at')->with('client')->each(
            function (ClientRequest $request) use (&$overdue) {
                $this->notify($request, 'overdue');
                $request->forceFill(['overdue_notified_at' => now()])->saveQuietly();
                $overdue++;
            },
        );

        $reminders = 0;
        ClientRequest::query()->open()
            ->whereNotNull('remind_at')->where('remind_at', '<=', now())
            ->whereNull('reminder_notified_at')
            ->with('client')
            ->each(function (ClientRequest $request) use (&$reminders) {
                $this->notify($request, 'reminder');
                $request->forceFill(['reminder_notified_at' => now()])->saveQuietly();
                $reminders++;
            });

        $this->info("Relances envoyées : {$overdue} demande(s) en retard, {$reminders} rappel(s).");

        return self::SUCCESS;
    }

    private function notify(ClientRequest $request, string $kind): void
    {
        foreach ($this->recipients($request) as $user) {
            $user->notify(new ClientRequestNotification($request, $kind));
        }
    }

    /**
     * La personne en charge ; à défaut, l'auteur s'il peut traiter les demandes ;
     * sinon les administrateurs et managers.
     *
     * @return Collection<int, User>
     */
    private function recipients(ClientRequest $request): Collection
    {
        $active = fn (?User $u) => $u && $u->is_active && ! $u->trashed();

        if ($active($request->assignee)) {
            return collect([$request->assignee]);
        }

        if ($active($request->creator) && $request->creator->can('client_requests.update')) {
            return collect([$request->creator]);
        }

        return User::role([Permissions::ROLE_ADMIN, Permissions::ROLE_MANAGER])->where('is_active', true)->get();
    }
}
