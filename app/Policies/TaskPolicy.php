<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Tâches. Modifier tout : tasks.update, ou tasks.update.own pour ses tâches (créées, ou de ses projets ;
 * pas les techniciens). Avancer (statut, sous-tâches, notes, photos) : en plus, les personnes affectées.
 * Affecter : tasks.assign.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['tasks.view', 'tasks.view.own']);
    }

    public function view(User $user, Task $task): bool
    {
        return Task::query()->visibleTo($user)->withTrashed()->whereKey($task->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->can('tasks.update')) {
            return true;
        }

        return $user->can('tasks.update.own') && ! $user->isTechnician()
            && ($task->created_by === $user->id || $task->project?->manager_id === $user->id);
    }

    public function progress(User $user, Task $task): bool
    {
        return $this->update($user, $task) || ($user->can('tasks.update.own') && $task->isAssignedTo($user));
    }

    public function assign(User $user, Task $task): bool
    {
        return $user->can('tasks.assign') && $this->update($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete') && $this->update($user, $task);
    }
}
