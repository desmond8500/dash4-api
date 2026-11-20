<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Notifications\Notification;

/** Une facture atteint un palier de relance (à relancer, ou relance envoyée automatiquement). */
class InvoiceReminderNotification extends Notification
{
    public function __construct(public Invoice $invoice, public int $level, public bool $sent) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $remaining = number_format($this->invoice->remainingAmount(), 0, ',', ' ');

        return [
            'kind' => $this->sent ? 'invoice.reminded' : 'invoice.overdue',
            'title' => $this->sent
                ? "Relance n° {$this->level} envoyée : {$this->invoice->number}"
                : "Facture à relancer : {$this->invoice->number}",
            'body' => "{$this->invoice->client?->name} — reste {$remaining} F CFA.",
            'invoice_id' => $this->invoice->id,
        ];
    }
}
