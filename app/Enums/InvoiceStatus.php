<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Issued => 'Émise',
            self::PartiallyPaid => 'Payée en partie',
            self::Paid => 'Payée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Factures émises qui comptent (chiffre d'affaires, reliquat, impayés). */
    public static function counted(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value, self::Paid->value];
    }
}
