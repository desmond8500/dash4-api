<?php

namespace App\Support;

use App\Models\Tool;
use App\Models\ToolLoan;
use Illuminate\Support\Carbon;

/** Outillage : états des outils, disponibilité et suivi des prêts (à rendre bientôt, en retard). */
final class Tools
{
    public const CONDITIONS = [
        'good' => 'Bon état',
        'worn' => 'Usé',
        'repair' => 'En réparation',
        'broken' => 'Hors service',
        'lost' => 'Perdu',
        'retired' => 'Réformé',
    ];

    /** États qui empêchent de prêter l'outil. */
    public const UNAVAILABLE_CONDITIONS = ['repair', 'broken', 'lost', 'retired'];

    public const STATUSES = [
        'available' => 'Disponible',
        'lent' => 'Prêté',
        'late' => 'Prêté — en retard',
        'unavailable' => 'Indisponible',
    ];

    /** Rappel de retard répété tous les N jours. */
    public const LATE_REMINDER_EVERY_DAYS = 3;

    /** @return array{value: string, label: string}|null */
    public static function label(array $list, ?string $value): ?array
    {
        return $value === null ? null : ['value' => $value, 'label' => $list[$value] ?? $value];
    }

    /** 'ongoing' (pas de date ou plus tard), 'soon' (à rendre demain ou aujourd'hui), 'late', ou 'returned'. */
    public static function loanState(ToolLoan $loan, ?Carbon $today = null): string
    {
        if ($loan->returned_at !== null) {
            return 'returned';
        }
        if ($loan->due_on === null) {
            return 'ongoing';
        }
        $days = ($today ?? today())->diffInDays($loan->due_on, false);

        return $days < 0 ? 'late' : ($days <= 1 ? 'soon' : 'ongoing');
    }

    /** Disponibilité de l'outil (le prêt en cours doit être chargé). */
    public static function status(Tool $tool, ?Carbon $today = null): string
    {
        $loan = $tool->relationLoaded('currentLoan') ? $tool->currentLoan : $tool->currentLoan()->first();
        if ($loan !== null) {
            return self::loanState($loan, $today) === 'late' ? 'late' : 'lent';
        }

        return in_array($tool->condition, self::UNAVAILABLE_CONDITIONS, true) ? 'unavailable' : 'available';
    }

    /** Nom affiché : « Perceuse Bosch (OUT-012) ». */
    public static function title(Tool $tool): string
    {
        return $tool->name.($tool->code ? " ({$tool->code})" : '');
    }
}
