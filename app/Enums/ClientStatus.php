<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Active => 'Client actif',
            self::Inactive => 'Inactif',
        };
    }
}
