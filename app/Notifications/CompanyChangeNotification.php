<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Alerte aux administrateurs : informations bancaires ou signature de l'entreprise modifiées (TODO §3).
 */
class CompanyChangeNotification extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $detail,
        public User $by,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'company.'.$this->kind,
            'title' => $this->title,
            'body' => $this->detail.' — par '.$this->by->full_name.', le '.now()->format('d/m/Y à H:i').'.',
        ];
    }

    /** Prévient tous les administrateurs actifs, sauf l'auteur du changement. */
    public static function send(string $kind, string $title, string $detail, User $by): void
    {
        User::role('admin')->where('is_active', true)->whereKeyNot($by->id)->get()
            ->each(fn (User $admin) => $admin->notify(new self($kind, $title, $detail, $by)));
    }
}
