<?php

namespace App\Policies;

use App\Models\Tool;
use App\Models\User;

/**
 * Outillage : `tools.view` voit tout l'outillage, `tools.view.own` les outils qu'il a en prêt.
 * Gestion avec `tools.create|update|delete`, prêts et retours avec `tools.lend`.
 */
class ToolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['tools.view', 'tools.view.own']);
    }

    public function view(User $user, Tool $tool): bool
    {
        return $user->can('tools.view')
            || ($user->can('tools.view.own') && $tool->loans()->whereNull('returned_at')->where('borrower_id', $user->id)->exists());
    }

    public function create(User $user): bool
    {
        return $user->can('tools.create');
    }

    public function update(User $user, Tool $tool): bool
    {
        return $user->can('tools.update');
    }

    public function delete(User $user, Tool $tool): bool
    {
        return $user->can('tools.delete');
    }

    public function lend(User $user, Tool $tool): bool
    {
        return $user->can('tools.lend');
    }
}
