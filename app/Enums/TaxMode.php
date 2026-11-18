<?php

namespace App\Enums;

/**
 * Taxe d'un devis ou d'une facture (comme dans Dash3) :
 *  - vat  : TVA ajoutée au total hors taxes (18 % par défaut) ;
 *  - brs  : BRS (retenue à la source, entreprises individuelles) déduite du total (5 % par défaut) ;
 *  - none : ni TVA ni BRS.
 */
enum TaxMode: string
{
    case Vat = 'vat';
    case Brs = 'brs';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Vat => 'TVA',
            self::Brs => 'BRS (retenue à la source)',
            self::None => 'Sans taxe',
        };
    }
}
