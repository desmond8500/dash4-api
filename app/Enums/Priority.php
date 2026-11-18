<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Basse',
            self::Normal => 'Normale',
            self::High => 'Urgente',
        };
    }

    /** Tri SQL : urgentes d'abord. */
    public static function orderSql(string $column = 'priority'): string
    {
        return "CASE {$column} WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END";
    }
}
