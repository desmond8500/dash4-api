<?php

namespace App\Enums;

/**
 * Type de compte : décide de l'interface affichée (ERP complet ou espace technicien).
 * Voir PROFILS_PERMISSIONS.md, §2.
 */
enum AccountType: string
{
    case Manager = 'manager';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Gestionnaire',
            self::Technician => 'Technicien',
        };
    }
}
