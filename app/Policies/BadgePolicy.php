<?php

namespace App\Policies;

use App\Models\Badge;
use App\Models\User;

/** Badges : `badges.*`, et le projet doit être visible (le chef de projet n'a que les siens). */
class BadgePolicy
{
    public function view(User $user, Badge $badge): bool
    {
        return $user->can('badges.view') && $user->can('view', $badge->project);
    }

    public function update(User $user, Badge $badge): bool
    {
        return $user->can('badges.update') && $user->can('view', $badge->project);
    }

    public function delete(User $user, Badge $badge): bool
    {
        return $user->can('badges.delete') && $user->can('view', $badge->project);
    }
}
