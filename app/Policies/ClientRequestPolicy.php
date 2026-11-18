<?php

namespace App\Policies;

use App\Models\ClientRequest;
use App\Models\User;

class ClientRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['client_requests.view', 'client_requests.view.own']);
    }

    public function view(User $user, ClientRequest $request): bool
    {
        return ClientRequest::query()->visibleTo($user)->whereKey($request->getKey())->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('client_requests.create');
    }

    public function update(User $user, ClientRequest $request): bool
    {
        return $user->can('client_requests.update') && $this->view($user, $request);
    }

    public function delete(User $user, ClientRequest $request): bool
    {
        return $user->can('client_requests.delete');
    }
}
