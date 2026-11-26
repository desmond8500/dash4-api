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

    /**
     * Suivi de chantier (bâtiments, systèmes, équipements, PV) — permissions `sites.*`.
     * `sites.view.own` : ses projets (chef de projet) ; pour un technicien, les projets où il est affecté
     * ou a une tâche (voir Project::scopeSiteVisibleTo).
     */
    public function viewSite(User $user, Project $project): bool
    {
        return Project::query()->siteVisibleTo($user)->whereKey($project->getKey())->withTrashed()->exists();
    }

    /** Créer et modifier : `sites.update`, ou `sites.update.own` sur ses projets (jamais un technicien). */
    public function manageSite(User $user, Project $project): bool
    {
        if ($user->can('sites.update')) {
            return true;
        }

        return $user->can('sites.update.own') && ! $user->isTechnician() && $project->isOwnedBy($user);
    }

    public function deleteSite(User $user, Project $project): bool
    {
        return $user->can('sites.delete') && $this->viewSite($user, $project);
    }
}
