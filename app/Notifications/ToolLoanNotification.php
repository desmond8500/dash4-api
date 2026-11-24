<?php

namespace App\Notifications;

use App\Models\ToolLoan;
use App\Support\Tools;
use Illuminate\Notifications\Notification;

/** Outillage : outil prêté, à rendre demain, à rendre aujourd'hui, en retard, rappel manuel, rendu. */
class ToolLoanNotification extends Notification
{
    /** @param 'lent'|'due_soon'|'due_today'|'late'|'reminder'|'returned' $event */
    public function __construct(public ToolLoan $loan, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $loan = $this->loan;
        $tool = Tools::title($loan->tool);
        $due = $loan->due_on?->format('d/m/Y');
        $who = $loan->borrowerLabel();
        $mine = $loan->borrower_id !== null && $notifiable->getKey() === $loan->borrower_id;

        [$title, $body] = match ($this->event) {
            'lent' => ["Outil prêté : {$tool}", 'Vous avez reçu cet outil en prêt'.($due ? ", à rendre le {$due}" : '').'.'],
            'due_soon' => ["Outil à rendre demain : {$tool}", $mine ? "Pensez à rendre l'outil demain ({$due})." : "{$who} doit rendre l'outil demain ({$due})."],
            'due_today' => ["Outil à rendre aujourd'hui : {$tool}", $mine ? "L'outil est à rendre aujourd'hui." : "{$who} doit rendre l'outil aujourd'hui."],
            'late' => ["Outil non rendu : {$tool}", ($mine ? "L'outil devait être rendu le {$due}." : "{$who} devait rendre l'outil le {$due}.").' Merci de le rapporter au magasin.'],
            'returned' => ["Outil rendu : {$tool}", "{$who} a rendu l'outil."],
            default => ["Rappel : {$tool}", 'Merci de rapporter cet outil au magasin'.($due ? " (retour prévu le {$due})" : '').'.'],
        };

        return ['kind' => 'tool_loan.'.$this->event, 'tool_id' => $loan->tool_id, 'tool_loan_id' => $loan->id, 'title' => $title, 'body' => $body];
    }
}
