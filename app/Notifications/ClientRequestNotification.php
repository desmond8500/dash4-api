<?php

namespace App\Notifications;

use App\Models\ClientRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Relance d'une demande client, enregistrée dans les notifications de l'utilisateur :
 *  - `overdue`  : demande encore « Nouvelle » après le délai (2 jours par défaut) ;
 *  - `reminder` : la date de rappel choisie est arrivée.
 */
class ClientRequestNotification extends Notification
{
    public function __construct(
        public ClientRequest $clientRequest,
        public string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->clientRequest;
        $who = $r->client?->name ?? $r->contact_name ?? $r->contact_phone;

        return [
            'kind' => 'client_request.'.$this->kind,
            'title' => $this->kind === 'overdue'
                ? 'Demande en attente depuis plus de '.ClientRequest::overdueDays().' jours'
                : 'Rappel : demande à traiter',
            'body' => ($who ? $who.' — ' : '').Str::limit($r->body, 120),
            'client_request_id' => $r->id,
        ];
    }
}
