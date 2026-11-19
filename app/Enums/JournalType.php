<?php

namespace App\Enums;

enum JournalType: string
{
    case Intervention = 'intervention';
    case Installation = 'installation';
    case Maintenance = 'maintenance';
    case Repair = 'repair';
    case Visit = 'visit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Intervention => 'Intervention',
            self::Installation => 'Installation',
            self::Maintenance => 'Maintenance',
            self::Repair => 'Dépannage',
            self::Visit => 'Visite technique',
            self::Other => 'Autre',
        };
    }
}
