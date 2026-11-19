<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Notifications\Notification;

/** Contrats : échéance proche, ou périodes à facturer. */
class ContractNotification extends Notification
{
    public function __construct(public ?Contract $contract, public string $event, public int $count = 0) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        if ($this->event === 'due') {
            return [
                'kind' => 'contract.due',
                'title' => "{$this->count} contrat(s) à facturer",
                'body' => 'Des périodes de contrats de maintenance sont à facturer.',
            ];
        }
        $c = $this->contract;

        return [
            'kind' => 'contract.ending',
            'contract_id' => $c?->id,
            'title' => "Échéance du contrat {$c?->number}",
            'body' => "« {$c?->title} » ({$c?->client?->name}) se termine le {$c?->end_date?->format('d/m/Y')}".($c?->tacit_renewal ? ' : il sera reconduit sauf résiliation.' : '.'),
        ];
    }
}
