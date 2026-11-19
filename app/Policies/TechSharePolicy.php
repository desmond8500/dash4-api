<?php

namespace App\Policies;

use App\Models\TechShare;
use App\Models\User;

/** Informations temporaires : le chef de projet gère celles qu'il a créées ou celles de ses projets. */
class TechSharePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['tech_shares.view', 'tech_shares.view.own']);
    }

    public function view(User $user, TechShare $share): bool
    {
        return TechShare::query()->visibleTo($user)->withTrashed()->whereKey($share->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('tech_shares.create');
    }

    /** Une information expirée ne se modifie plus (son contenu est effacé). */
    public function update(User $user, TechShare $share): bool
    {
        if ($share->isExpired() || ! $user->can('tech_shares.update')) {
            return false;
        }

        return $user->can('tech_shares.view') || $share->created_by === $user->id
            || (! $user->isTechnician() && $share->project?->manager_id === $user->id);
    }

    public function delete(User $user, TechShare $share): bool
    {
        return $user->can('tech_shares.delete') || ($share->created_by === $user->id && $user->can('tech_shares.create'));
    }
}
