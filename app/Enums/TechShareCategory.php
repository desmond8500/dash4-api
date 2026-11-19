<?php

namespace App\Enums;

enum TechShareCategory: string
{
    case Info = 'info';
    case Access = 'access';
    case Equipment = 'equipment';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Access => 'Accès / codes',
            self::Equipment => 'Matériel',
            self::Document => 'Document',
        };
    }
}
