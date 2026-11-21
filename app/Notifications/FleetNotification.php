<?php

namespace App\Notifications;

use App\Models\Vehicle;
use Illuminate\Notifications\Notification;

/** Parc automobile : papier qui expire (ou expiré), entretien à prévoir (ou en retard). */
class FleetNotification extends Notification
{
    /** @param 'paper_soon'|'paper_expired'|'maintenance_soon'|'maintenance_late' $event */
    public function __construct(public Vehicle $vehicle, public string $event, public string $subject, public ?string $date = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $v = $this->vehicle;
        $name = $v->name.($v->plate ? " ({$v->plate})" : '');
        $date = $this->date ? \Illuminate\Support\Carbon::parse($this->date)->format('d/m/Y') : null;

        [$title, $body] = match ($this->event) {
            'paper_expired' => ["{$this->subject} à renouveler d'urgence : {$name}", "L'échéance du papier « {$this->subject} » est dépassée".($date ? " ({$date})" : '').'.'],
            'paper_soon' => ["{$this->subject} à renouveler : {$name}", "Échéance du papier « {$this->subject} » : {$date}."],
            'maintenance_late' => ["{$this->subject} en retard : {$name}", "L'entretien « {$this->subject} » est dépassé".($date ? " (prévu le {$date})" : '').'.'],
            default => ["{$this->subject} à prévoir : {$name}", "L'entretien « {$this->subject} » approche".($date ? " (prévu le {$date})" : '').'.'],
        };

        return ['kind' => 'vehicle.'.$this->event, 'vehicle_id' => $v->id, 'title' => $title, 'body' => $body];
    }
}
