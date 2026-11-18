<?php

namespace App\Enums;

enum ClientType: string
{
    case Company = 'company';
    case Individual = 'individual';
    case PublicBody = 'public_body';
    case Association = 'association';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Entreprise',
            self::Individual => 'Particulier',
            self::PublicBody => 'Organisme public',
            self::Association => 'Association',
        };
    }
}
