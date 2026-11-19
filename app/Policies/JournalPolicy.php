<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;

/** Journaux : journals.update(.own) — l'auteur, les intervenants et le chef de projet modifient le leur. */
class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['journals.view', 'journals.view.own']);
    }

    public function view(User $user, Journal $journal): bool
    {
        return Journal::query()->visibleTo($user)->withTrashed()->whereKey($journal->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('journals.create');
    }

    public function update(User $user, Journal $journal): bool
    {
        if ($user->can('journals.update')) {
            return true;
        }

        return $user->can('journals.update.own') && ($journal->loadMissing('participants')->involves($user)
            || (! $user->isTechnician() && $journal->project?->manager_id === $user->id));
    }

    public function delete(User $user, Journal $journal): bool
    {
        return $user->can('journals.delete') || ($journal->created_by === $user->id && $user->can('journals.update.own') && $journal->created_at?->gt(now()->subDay()));
    }
}
