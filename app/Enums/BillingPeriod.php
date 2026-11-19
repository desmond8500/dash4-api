<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuelle',
            self::Quarterly => 'Trimestrielle',
            self::HalfYearly => 'Semestrielle',
            self::Yearly => 'Annuelle',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    /** Fin de la période qui commence le `start` (veille du début de la suivante). */
    public function periodEnd(Carbon $start): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($this->months())->subDay();
    }
}
