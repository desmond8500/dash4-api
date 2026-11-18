<?php

namespace App\Enums;

enum DeliveryNoteType: string
{
    case Delivery = 'delivery';
    case Works = 'works';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Bordereau de livraison',
            self::Works => 'Bordereau de travaux',
        };
    }
}
