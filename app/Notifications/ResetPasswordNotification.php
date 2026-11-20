<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail « mot de passe oublié » : lien vers la page du front web, valable 60 minutes. */
class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('dash4.web_url').'/mot-de-passe/nouveau?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Dash4 — nouveau mot de passe')
            ->greeting("Bonjour {$notifiable->firstname},")
            ->line('Vous avez demandé à changer votre mot de passe Dash4.')
            ->action('Choisir un nouveau mot de passe', $url)
            ->line("Ce lien est valable {$minutes} minutes et ne sert qu'une fois.")
            ->line("Si vous n'avez rien demandé, ignorez ce message : votre mot de passe ne change pas.")
            ->salutation('— Dash4');
    }
}
