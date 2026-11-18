<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;

class ProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['stock.view', 'purchases.view', 'purchases.view.own']);
    }

    public function view(User $user, Provider $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['stock.create', 'purchases.create']);
    }

    public function update(User $user, Provider $model): bool
    {
        return $user->canAny(['stock.update', 'purchases.update']);
    }

    public function delete(User $user, Provider $model): bool
    {
        return $user->canAny(['stock.delete', 'purchases.delete']);
    }
}
