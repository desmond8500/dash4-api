<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandé',
            self::PartiallyReceived => 'Reçu en partie',
            self::Received => 'Reçu',
            self::Cancelled => 'Annulé',
        };
    }

    public function receivable(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }
}
