<?php

namespace App\Enums;

/** Dépense : soumise → validée ou refusée → remboursée (si avancée par la personne). */
enum ExpenseStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reimbursed = 'reimbursed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'À valider',
            self::Approved => 'Validée',
            self::Rejected => 'Refusée',
            self::Reimbursed => 'Remboursée',
        };
    }

    /** Dépenses qui comptent dans le bilan. */
    public static function counted(): array
    {
        return [self::Approved->value, self::Reimbursed->value];
    }
}
