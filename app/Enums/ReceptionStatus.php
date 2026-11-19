<?php

namespace App\Enums;

enum ReceptionStatus: string
{
    case Draft = 'draft';
    case WithReserves = 'with_reserves';
    case Accepted = 'accepted';
    case Signed = 'signed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::WithReserves => 'Réception avec réserves',
            self::Accepted => 'Réception sans réserve',
            self::Signed => 'Signé',
        };
    }
}
