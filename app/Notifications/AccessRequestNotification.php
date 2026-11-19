<?php

namespace App\Notifications;

use App\Models\TechAccessRequest;
use Illuminate\Notifications\Notification;

/** Demande d'accès : nouvelle (pour qui décide) ou décidée (pour le demandeur). */
class AccessRequestNotification extends Notification
{
    public function __construct(public TechAccessRequest $accessRequest, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->accessRequest;
        $who = $r->user?->full_name ?? 'Un technicien';

        return match ($this->event) {
            'created' => [
                'kind' => 'access_request.created',
                'tech_access_request_id' => $r->id,
                'title' => "Demande d'accès : {$r->subject}",
                'body' => "{$who} demande un accès".($r->project ? " ({$r->project->name})" : '').($r->needed_on ? ' pour le '.$r->needed_on->format('d/m') : '').'.',
            ],
            default => [
                'kind' => 'access_request.decided',
                'tech_access_request_id' => $r->id,
                'tech_share_id' => $r->tech_share_id,
                'title' => "Demande d'accès {$r->status->label()} : {$r->subject}",
                'body' => trim(($r->decider ? "{$r->decider->full_name} a répondu" : 'Réponse reçue').($r->tech_share_id ? ' ; l\'information est dans vos informations temporaires.' : '.')),
            ],
        };
    }
}
