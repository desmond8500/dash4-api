<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['projects.view', 'projects.view.own']);
    }

    public function view(User $user, Project $project): bool
    {
        return Project::query()->visibleTo($user)->whereKey($project->getKey())->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('projects.create');
    }

    /** `projects.update` : tous ; `projects.update.own` (chef de projet) : ses projets. */
    public function update(User $user, Project $project): bool
    {
        if ($user->can('projects.update')) {
            return true;
        }

        return $user->can('projects.update.own') && $project->isOwnedBy($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }
}
