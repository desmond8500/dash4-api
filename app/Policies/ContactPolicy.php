<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['clients.view', 'clients.view.own']);
    }

    public function view(User $user, Contact $contact): bool
    {
        return Contact::query()->visibleTo($user)->whereKey($contact->getKey())->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can('clients.update');
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('clients.delete');
    }
}
