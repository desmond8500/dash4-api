<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['clients.view', 'clients.view.own']);
    }

    public function view(User $user, Client $client): bool
    {
        return Client::query()->visibleTo($user)->whereKey($client->getKey())->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clients.update');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can('clients.delete');
    }
}
