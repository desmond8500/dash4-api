<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Relances de paiement. Les paliers (Settings `reminders.days`, 7, 15 et 30 jours par défaut) se comptent
 * à partir de l'échéance (ou de l'émission sans échéance). Une relance part toujours avec la facture en PDF
 * et son reste à payer ; en automatique, elle est envoyée au nom de la personne qui a émis la facture.
 */
final class InvoiceReminders
{
    /** Factures émises, non soldées, dont l'échéance est passée. */
    public static function overdueQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->whereRaw('coalesce(due_at, issued_at) < ?', [now()->toDateString()]);
    }

    public static function daysOverdue(Invoice $invoice, ?Carbon $today = null): int
    {
        $from = $invoice->due_at ?? $invoice->issued_at;

        return $from ? max(0, (int) $from->copy()->startOfDay()->diffInDays(($today ?? now())->copy()->startOfDay(), false)) : 0;
    }

    /** Palier atteint aujourd'hui (0 : pas encore de relance à faire). */
    public static function levelReached(Invoice $invoice, ?Carbon $today = null): int
    {
        $days = self::daysOverdue($invoice, $today);

        return count(array_filter((array) Settings::get('reminders')['days'], fn ($d) => $days >= (int) $d));
    }

    /** Objet et texte proposés pour la relance suivante. */
    public static function draft(Invoice $invoice, User $user): array
    {
        $level = $invoice->reminders()->count() + 1;
        $remaining = number_format($invoice->remainingAmount(), 0, ',', ' ');
        $due = ($invoice->due_at ?? $invoice->issued_at)?->format('d/m/Y');
        $intro = match (true) {
            $level === 1 => "Sauf erreur de notre part, la facture {$invoice->number} ({$invoice->title}) arrivée à échéance le {$due} n'est pas encore réglée.",
            $level === 2 => "Nous revenons vers vous au sujet de la facture {$invoice->number} ({$invoice->title}), échue depuis le {$due} et toujours impayée.",
            default => "Malgré nos précédentes relances, la facture {$invoice->number} ({$invoice->title}), échue depuis le {$due}, reste impayée.",
        };

        return DocumentMailer::draft(
            $invoice->client,
            $user,
            ($level > 1 ? "Relance n° {$level} — " : 'Rappel — ')."facture {$invoice->number}",
            "Bonjour,\n\n{$intro}\nMontant restant à régler : {$remaining} F CFA.\n\n"
                ."Vous trouverez la facture ci-jointe. Si le règlement a déjà été fait, merci de ne pas tenir compte de ce message.\n\n"
                .'Nous restons à votre disposition pour toute question.',
        ) + ['level' => $level];
    }

    /** Envoie la relance et la note dans l'historique. */
    public static function send(Invoice $invoice, array $data, User $user, bool $automatic = false): InvoiceReminder
    {
        $level = $invoice->reminders()->count() + 1;
        $email = DocumentMailer::send(
            $invoice,
            $data,
            [['name' => PdfName::invoice($invoice->loadMissing('client')), 'data' => QuotePdf::invoice($invoice)->output()]],
            $user,
            "Relance n° {$level} de la facture {$invoice->number}",
        );
        $reminder = new InvoiceReminder(['level' => $level, 'automatic' => $automatic, 'sent_at' => now()]);
        $reminder->quote_email_id = $email->id;
        $reminder->sent_by = $user->id;
        $invoice->reminders()->save($reminder);
        $invoice->forceFill(['reminder_level' => max($invoice->reminder_level, $level)])->saveQuietly();

        return $reminder;
    }
}
