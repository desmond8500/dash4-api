<?php

namespace App\Enums;

/** Ce que facture une facture issue d'un devis. */
enum InvoiceScope: string
{
    case All = 'all';
    case Equipment = 'equipment';
    case EquipmentAccessories = 'equipment_accessories';
    case Labor = 'labor';
    case Remainder = 'remainder';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Tout le devis',
            self::Equipment => 'Matériel',
            self::EquipmentAccessories => 'Matériel et accessoires',
            self::Labor => "Main-d'œuvre",
            self::Remainder => 'Reliquat',
            self::Custom => 'Libre',
        };
    }

    /** @return list<QuoteLineCategory>|null catégories de lignes concernées (null : toutes) */
    public function categories(): ?array
    {
        return match ($this) {
            self::Equipment => [QuoteLineCategory::Central, QuoteLineCategory::Device, QuoteLineCategory::Cable],
            self::EquipmentAccessories => [QuoteLineCategory::Central, QuoteLineCategory::Device, QuoteLineCategory::Cable, QuoteLineCategory::Accessory],
            self::Labor => [QuoteLineCategory::Service],
            default => null,
        };
    }

    /** Titre proposé (modifiable). */
    public function defaultTitle(float $percent): string
    {
        $pct = rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');

        return match (true) {
            $this === self::Remainder => 'Facture de solde',
            $percent < 100 && $this === self::All => "Facture d'acompte de {$pct} %",
            $percent < 100 => "Facture d'acompte de {$pct} % — ".mb_strtolower($this->label()),
            $this === self::All => 'Facture',
            default => 'Facture — '.mb_strtolower($this->label()),
        };
    }
}
