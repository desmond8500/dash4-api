<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Dépenses. accounting.view / create / update / delete : tout (comptable, manager, admin) ;
 * accounting.view.own / create.own : ses dépenses et celles de ses caisses, modifiables tant qu'elles sont à valider.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['accounting.view', 'accounting.view.own']);
    }

    public function view(User $user, Expense $expense): bool
    {
        return Expense::query()->visibleTo($user)->whereKey($expense->id)->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->canAny(['accounting.create', 'accounting.create.own']);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can('accounting.update')
            || ($expense->isSubmitted() && $expense->created_by === $user->id && $user->can('accounting.create.own'));
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can('accounting.delete')
            || ($expense->isSubmitted() && $expense->created_by === $user->id && $user->can('accounting.create.own'));
    }

    /** Valider, refuser, rembourser. */
    public function decide(User $user, Expense $expense): bool
    {
        return $user->can('accounting.update');
    }
}
