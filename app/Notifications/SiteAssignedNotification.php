<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Notifications\Notification;

/** Un chantier vous est affecté (technicien) : il apparaît dans « Plans » avec ses bâtiments, plans et équipements. */
class SiteAssignedNotification extends Notification
{
    public function __construct(public Project $project, public ?string $by = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $p = $this->project;

        return [
            'kind' => 'site.assigned',
            'project_id' => $p->id,
            'title' => 'Chantier affecté : '.$p->name,
            'body' => trim(($this->by ? "{$this->by} vous a affecté à ce chantier" : 'Ce chantier vous est affecté').($p->address ? " ({$p->address})" : '').'.'),
        ];
    }
}
