<?php

namespace App\Policies;

use App\Models\Cv;
use App\Models\User;

/** Le CV appartient à son auteur ; un administrateur voit ceux qui lui sont partagés. */
class CvPolicy
{
    public function view(User $user, Cv $cv): bool
    {
        return $cv->user_id === $user->id || ($cv->shared && $user->hasRole('admin'));
    }

    public function update(User $user, Cv $cv): bool
    {
        return $cv->user_id === $user->id;
    }

    public function delete(User $user, Cv $cv): bool
    {
        return $cv->user_id === $user->id;
    }
}
