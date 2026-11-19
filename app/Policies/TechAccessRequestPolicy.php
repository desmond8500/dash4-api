<?php

namespace App\Policies;

use App\Models\TechAccessRequest;
use App\Models\User;

/** Demandes d'accès : le technicien crée et suit les siennes ; le chef de projet décide pour ses projets. */
class TechAccessRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['tech_access_requests.view', 'tech_access_requests.view.own']);
    }

    public function view(User $user, TechAccessRequest $request): bool
    {
        return TechAccessRequest::query()->visibleTo($user)->withTrashed()->whereKey($request->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('tech_access_requests.create');
    }

    public function decide(User $user, TechAccessRequest $request): bool
    {
        if (! $request->isPending() || ! $user->can('tech_access_requests.decide') || $request->user_id === $user->id) {
            return false;
        }

        return $user->can('tech_access_requests.view')
            || (! $user->isTechnician() && $request->project?->manager_id === $user->id);
    }

    /** Annuler : le demandeur tant qu'elle est en attente ; supprimer : qui voit et décide tout. */
    public function delete(User $user, TechAccessRequest $request): bool
    {
        return ($request->user_id === $user->id && $request->isPending())
            || $user->can('tech_access_requests.view') && $user->can('tech_access_requests.decide');
    }
}
