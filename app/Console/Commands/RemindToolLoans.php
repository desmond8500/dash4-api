<?php

namespace App\Console\Commands;

use App\Models\ToolLoan;
use App\Models\User;
use App\Notifications\ToolLoanNotification;
use App\Support\Tools;
use Illuminate\Console\Command;

/**
 * Chaque matin : rappelle les outils à rendre. La veille et le jour du retour, l'emprunteur est prévenu ;
 * en retard, l'emprunteur, celui qui a prêté et les gestionnaires de l'outillage le sont, puis tous les 3 jours.
 */
class RemindToolLoans extends Command
{
    protected $signature = 'tools:remind';

    protected $description = 'Rappels des outils prêtés à rendre ou en retard';

    public function handle(): int
    {
        $today = today();
        $managers = User::permission('tools.update')->where('is_active', true)->get();
        $sent = 0;

        ToolLoan::query()->whereNull('returned_at')->whereNotNull('due_on')->whereDate('due_on', '<=', $today->copy()->addDay())
            ->with(['tool', 'borrower', 'lender'])->get()
            ->each(function (ToolLoan $loan) use ($today, $managers, &$sent) {
                if ($loan->tool === null || $loan->tool->trashed()) {
                    return;
                }
                $days = (int) round($today->diffInDays($loan->due_on, false));
                $borrower = $loan->borrower && $loan->borrower->is_active ? collect([$loan->borrower]) : collect();

                if ($days >= 1) {
                    [$event, $level, $recipients] = ['due_soon', 1, $borrower];
                } elseif ($days === 0) {
                    [$event, $level, $recipients] = ['due_today', 2, $borrower];
                } else {
                    $event = 'late';
                    $level = 3;
                    $recipients = $borrower
                        ->when($loan->lender && $loan->lender->is_active, fn ($c) => $c->push($loan->lender))
                        ->merge($managers);
                }

                $repeat = $level === 3 && $loan->reminder_level >= 3
                    && ($loan->last_reminded_on === null || (int) round($loan->last_reminded_on->diffInDays($today)) >= Tools::LATE_REMINDER_EVERY_DAYS);
                if ($level <= $loan->reminder_level && ! $repeat) {
                    return;
                }

                $recipients->unique('id')->each(function (User $user) use ($loan, $event, &$sent) {
                    $user->notify(new ToolLoanNotification($loan, $event));
                    $sent++;
                });
                $loan->forceFill(['reminder_level' => max($level, $loan->reminder_level), 'last_reminded_on' => $today])->save();
            });

        $this->info("{$sent} rappel(s) d'outillage envoyé(s).");

        return self::SUCCESS;
    }
}
