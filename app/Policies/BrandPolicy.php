<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['stock.view', 'purchases.view', 'purchases.view.own']);
    }

    public function view(User $user, Brand $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['stock.create', 'purchases.create']);
    }

    public function update(User $user, Brand $model): bool
    {
        return $user->canAny(['stock.update', 'purchases.update']);
    }

    public function delete(User $user, Brand $model): bool
    {
        return $user->canAny(['stock.delete', 'purchases.delete']);
    }
}
