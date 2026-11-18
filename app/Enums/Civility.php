<?php

namespace App\Enums;

enum Civility: string
{
    case Mr = 'mr';
    case Mrs = 'mrs';

    public function label(): string
    {
        return match ($this) {
            self::Mr => 'M.',
            self::Mrs => 'Mme',
        };
    }
}
