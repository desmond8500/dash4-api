<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Notifications\Notification;

/** Dépense à valider (aux comptables) ; validée, refusée ou remboursée (à son auteur). */
class ExpenseNotification extends Notification
{
    public function __construct(public Expense $expense, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $e = $this->expense;
        $amount = number_format($e->amount, 0, ',', ' ').' F CFA';

        return [
            'kind' => 'expense.'.$this->event,
            'expense_id' => $e->id,
            ...match ($this->event) {
                'submitted' => ['title' => 'Dépense à valider', 'body' => "{$e->creator?->full_name} : {$e->label} — {$amount}."],
                'approved' => ['title' => 'Dépense validée', 'body' => "{$e->label} — {$amount}".($e->advanced ? ' : elle vous sera remboursée.' : '.')],
                'rejected' => ['title' => 'Dépense refusée', 'body' => "{$e->label} — {$amount}".($e->rejection_reason ? " : {$e->rejection_reason}" : '.')],
                default => ['title' => 'Dépense remboursée', 'body' => "{$e->label} — {$amount}."],
            },
        ];
    }

    public static function submitted(Expense $expense): void
    {
        User::permission('accounting.update')->where('is_active', true)->whereKeyNot($expense->created_by)->get()
            ->each(fn (User $u) => $u->notify(new self($expense, 'submitted')));
    }

    public static function toAuthor(Expense $expense, string $event, User $by): void
    {
        if ($expense->creator && $expense->creator->id !== $by->id) {
            $expense->creator->notify(new self($expense, $event));
        }
    }
}
