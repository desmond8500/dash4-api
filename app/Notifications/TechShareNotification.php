<?php

namespace App\Notifications;

use App\Models\TechShare;
use Illuminate\Notifications\Notification;

/** Une information temporaire vous est envoyée (le contenu n'est pas recopié dans la notification). */
class TechShareNotification extends Notification
{
    public function __construct(public TechShare $share, public ?string $by = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->share;
        $until = $s->expires_at ? ' jusqu\'au '.$s->expires_at->format('d/m à H:i') : ($s->expires_with_task ? ' jusqu\'à la fin de la tâche' : '');

        return [
            'kind' => 'tech_share.received',
            'tech_share_id' => $s->id,
            'title' => 'Information : '.$s->title,
            'body' => trim(($this->by ? "{$this->by} vous a envoyé une information" : 'Nouvelle information').", visible{$until}."),
        ];
    }
}
