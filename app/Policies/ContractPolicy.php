<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/** Contrats et forfaits : `contracts.*` (lecture pour le chef de projet et le comptable). */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.create');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update');
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('contracts.delete');
    }
}
