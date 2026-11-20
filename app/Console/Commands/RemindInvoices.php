<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceReminderNotification;
use App\Support\InvoiceReminders;
use App\Support\Settings;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Chaque matin : une facture impayée qui atteint un palier de relance est signalée à ceux qui émettent
 * les factures (une fois par palier). Avec l'envoi automatique, la relance part au client.
 */
class RemindInvoices extends Command
{
    protected $signature = 'invoices:remind';

    protected $description = 'Signale (et envoie si réglé ainsi) les relances des factures impayées';

    public function handle(): int
    {
        $auto = (bool) Settings::get('reminders')['auto'];
        $recipients = User::permission('invoices.issue')->where('is_active', true)->get();
        $count = 0;

        InvoiceReminders::overdueQuery()->with(['client.emails', 'issuer'])->get()->each(function (Invoice $invoice) use ($auto, $recipients, &$count) {
            $level = InvoiceReminders::levelReached($invoice);
            if ($level <= $invoice->reminder_level) {
                return;
            }
            $sent = false;
            $address = $invoice->client?->emails->first()?->address;
            $sender = $invoice->issuer?->is_active ? $invoice->issuer : $recipients->first();
            if ($auto && $address && $sender) {
                try {
                    $draft = InvoiceReminders::draft($invoice, $sender);
                    InvoiceReminders::send($invoice, ['to' => [$address], 'subject' => $draft['subject'], 'message' => $draft['message'], 'signature_id' => $draft['default_signature_id']], $sender, true);
                    $sent = true;
                } catch (HttpException $e) {
                    $this->warn("{$invoice->number} : {$e->getMessage()}");
                }
            }
            $invoice->forceFill(['reminder_level' => $level])->saveQuietly();
            $recipients->each->notify(new InvoiceReminderNotification($invoice, $invoice->reminders()->count() ?: $level, $sent));
            $count++;
        });

        $this->info("{$count} facture(s) signalée(s).");

        return self::SUCCESS;
    }
}
