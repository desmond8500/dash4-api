<?php

namespace App\Enums;

/**
 * Catégorie d'une ligne de devis (Dash3 : priorite_id).
 * Reprise : 0, 1 → central ; 2, 3, 4 → device ; 5 → cable ; 6 → accessory ; 7 → service.
 */
enum QuoteLineCategory: string
{
    case Central = 'central';
    case Device = 'device';
    case Cable = 'cable';
    case Accessory = 'accessory';
    case Service = 'service';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Central => 'Centrale',
            self::Device => 'Organe',
            self::Cable => 'Câble',
            self::Accessory => 'Accessoires',
            self::Service => "Main-d'œuvre / forfait",
            self::Other => 'Autre',
        };
    }

    public static function fromLegacy(int $priority): self
    {
        return match ($priority) {
            0, 1 => self::Central,
            2, 3, 4 => self::Device,
            5 => self::Cable,
            6 => self::Accessory,
            7 => self::Service,
            default => self::Other,
        };
    }
}
