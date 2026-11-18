<?php

namespace App\Enums;

enum ClientRequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nouvelle',
            self::InProgress => 'En cours',
            self::Done => 'Traitée',
            self::Dismissed => 'Classée sans suite',
        };
    }

    /** Statuts de la boîte « À traiter ». */
    public static function open(): array
    {
        return [self::New->value, self::InProgress->value];
    }

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Dismissed;
    }
}
