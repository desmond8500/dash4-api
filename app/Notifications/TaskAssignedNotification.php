<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

/** Une tâche vous est confiée. */
class TaskAssignedNotification extends Notification
{
    public function __construct(public Task $task, public ?string $by = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $t = $this->task;
        $when = $t->starts_at ? ' le '.$t->starts_at->format('d/m à H:i') : ($t->due_date ? ' avant le '.$t->due_date->format('d/m') : '');

        return [
            'kind' => 'task.assigned',
            'task_id' => $t->id,
            'title' => 'Nouvelle tâche : '.$t->title,
            'body' => trim(($this->by ? "{$this->by} vous a confié cette tâche" : 'Cette tâche vous est confiée').$when.($t->project ? " ({$t->project->name})" : '').'.'),
        ];
    }
}
