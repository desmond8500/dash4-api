<?php

namespace App\Enums;

enum ClientRequestSource: string
{
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Visit = 'visit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Appel',
            self::Whatsapp => 'WhatsApp',
            self::Email => 'E-mail',
            self::Visit => 'Visite',
            self::Other => 'Autre',
        };
    }
}
