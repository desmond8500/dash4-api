<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Sent => 'Envoyé',
            self::Accepted => 'Accepté',
            self::Refused => 'Refusé',
            self::Cancelled => 'Annulé',
        };
    }

    /** Statuts qui engagent l'entreprise : réservés à quotes.validate. */
    public function needsValidation(): bool
    {
        return $this === self::Accepted || $this === self::Refused;
    }
}
