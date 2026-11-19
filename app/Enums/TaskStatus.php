<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'À faire',
            self::InProgress => 'En cours',
            self::Blocked => 'Bloquée',
            self::Done => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Todo, self::InProgress, self::Blocked], true);
    }

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Todo->value, self::InProgress->value, self::Blocked->value];
    }
}
