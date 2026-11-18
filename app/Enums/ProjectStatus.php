<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'À venir',
            self::InProgress => 'En cours',
            self::OnHold => 'En pause',
            self::Completed => 'Terminé',
            self::Cancelled => 'Annulé',
        };
    }

    /** Projets encore actifs (liste par défaut). */
    public static function active(): array
    {
        return [self::Planned->value, self::InProgress->value, self::OnHold->value];
    }
}
