<?php

namespace App\Policies;

use App\Models\User;

/**
 * Règles de gestion des utilisateurs (PROFILS_PERMISSIONS.md, §5) :
 * - `users.manage` est nécessaire pour créer ou modifier ;
 * - seul un administrateur peut créer, modifier ou désactiver un administrateur ;
 * - personne ne modifie ses propres rôles ni ne se désactive (évite l'auto-promotion et le blocage).
 * La règle « il reste toujours un administrateur actif » est vérifiée dans UserController.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function update(User $actor, User $user): bool
    {
        if (! $actor->can('users.manage')) {
            return false;
        }

        return ! $user->isAdmin() || $actor->isAdmin();
    }
}
