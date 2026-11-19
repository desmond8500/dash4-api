<?php

namespace App\Enums;

enum EquipmentStatus: string
{
    case Planned = 'planned';
    case Installed = 'installed';
    case Commissioned = 'commissioned';
    case Faulty = 'faulty';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Prévu',
            self::Installed => 'Posé',
            self::Commissioned => 'Mis en service',
            self::Faulty => 'En panne',
            self::Removed => 'Déposé',
        };
    }
}
