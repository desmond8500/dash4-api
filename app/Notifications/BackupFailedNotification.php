<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** La sauvegarde de la nuit a échoué : prévenir les administrateurs. */
class BackupFailedNotification extends Notification
{
    public function __construct(public string $error) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'backup.failed',
            'title' => 'Sauvegarde impossible',
            'body' => mb_substr($this->error, 0, 300),
        ];
    }
}
