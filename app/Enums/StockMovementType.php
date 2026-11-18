<?php

namespace App\Enums;

enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Inventory = 'inventory';
    case Purchase = 'purchase';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrée',
            self::Out => 'Sortie',
            self::Inventory => 'Inventaire',
            self::Purchase => 'Réception d\'achat',
            self::Delivery => 'Bordereau de livraison',
        };
    }

    /** Mouvements saisis à la main (les autres viennent d'un achat ou d'un bordereau). */
    public static function manual(): array
    {
        return [self::In, self::Out, self::Inventory];
    }
}
