<?php

namespace App\Support;

use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehiclePaper;
use Illuminate\Support\Carbon;

/**
 * Parc automobile : listes de valeurs et alertes (papiers qui expirent, entretiens à faire).
 * Une alerte est due 30 jours avant l'expiration d'un papier, 15 jours ou 500 km avant un entretien.
 */
final class Fleet
{
    public const KINDS = ['car' => 'Voiture', 'utility' => 'Utilitaire', 'truck' => 'Camion', 'scooter' => 'Scooter', 'motorcycle' => 'Moto', 'other' => 'Autre'];

    public const FUELS = ['petrol' => 'Essence', 'diesel' => 'Diesel', 'hybrid' => 'Hybride', 'electric' => 'Électrique', 'other' => 'Autre'];

    public const STATUSES = ['active' => 'En service', 'repair' => 'Au garage', 'out_of_service' => 'Hors service', 'sold' => 'Vendu'];

    /** Dossiers administratifs. */
    public const PAPER_TYPES = [
        'insurance' => 'Assurance',
        'registration' => 'Carte grise',
        'cmc' => 'Carte de mise en circulation (CMC)',
        'inspection' => 'Visite technique',
        'tax' => 'Vignette / taxe',
        'license' => 'Autorisation / licence',
        'other' => 'Autre',
    ];

    public const MAINTENANCE_TYPES = [
        'oil_change' => 'Vidange',
        'service' => 'Révision',
        'tyres' => 'Pneus',
        'brakes' => 'Freins',
        'battery' => 'Batterie',
        'repair' => 'Réparation',
        'other' => 'Autre',
    ];

    public const PAPER_WARNING_DAYS = 30;

    public const SERVICE_WARNING_DAYS = 15;

    public const SERVICE_WARNING_KM = 500;

    public static function label(array $list, ?string $value): ?array
    {
        return $value === null ? null : ['value' => $value, 'label' => $list[$value] ?? $value];
    }

    /** État d'un papier : `ok`, `soon` (moins de 30 jours), `expired`, ou null sans date d'expiration. */
    public static function paperState(VehiclePaper $paper, ?Carbon $today = null): ?string
    {
        if ($paper->expires_on === null) {
            return null;
        }
        $today ??= today();

        return match (true) {
            $paper->expires_on->lt($today) => 'expired',
            $paper->expires_on->lte($today->copy()->addDays(self::PAPER_WARNING_DAYS)) => 'soon',
            default => 'ok',
        };
    }

    /**
     * Prochains entretiens prévus : pour chaque type, le plus récent qui annonce une date ou un kilométrage.
     * Avec un intervalle sur le véhicule et sans prochain entretien saisi, la révision se calcule depuis la dernière.
     *
     * @return list<array{type: string, label: string, next_on: ?string, next_km: ?int, state: string, maintenance_id: ?int}>
     */
    public static function upcoming(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= today();
        $maintenances = $vehicle->relationLoaded('maintenances') ? $vehicle->maintenances : $vehicle->maintenances()->get();
        $out = [];
        foreach ($maintenances->sortByDesc(fn ($m) => $m->done_on->format('Ymd').str_pad((string) $m->id, 10, '0', STR_PAD_LEFT))->groupBy('type') as $type => $items) {
            /** @var VehicleMaintenance $last */
            $last = $items->first();
            $nextOn = $last->next_on;
            $nextKm = $last->next_km;
            if ($nextOn === null && $nextKm === null && in_array($type, ['service', 'oil_change'], true)) {
                $nextOn = $vehicle->service_interval_months ? $last->done_on->copy()->addMonthsNoOverflow($vehicle->service_interval_months) : null;
                $nextKm = $vehicle->service_interval_km && $last->km !== null ? $last->km + $vehicle->service_interval_km : null;
            }
            if ($nextOn === null && $nextKm === null) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'label' => self::MAINTENANCE_TYPES[$type] ?? $type,
                'next_on' => $nextOn?->toDateString(),
                'next_km' => $nextKm,
                'state' => self::serviceState($nextOn, $nextKm, $vehicle->mileage, $today),
                'maintenance_id' => $last->id,
            ];
        }
        usort($out, fn ($a, $b) => [self::rank($a['state']), $a['next_on'] ?? '9999'] <=> [self::rank($b['state']), $b['next_on'] ?? '9999']);

        return $out;
    }

    public static function serviceState(?Carbon $nextOn, ?int $nextKm, int $mileage, Carbon $today): string
    {
        $late = ($nextOn && $nextOn->lt($today)) || ($nextKm !== null && $mileage >= $nextKm);
        $soon = ($nextOn && $nextOn->lte($today->copy()->addDays(self::SERVICE_WARNING_DAYS)))
            || ($nextKm !== null && $mileage + self::SERVICE_WARNING_KM >= $nextKm);

        return $late ? 'late' : ($soon ? 'soon' : 'ok');
    }

    private static function rank(string $state): int
    {
        return ['late' => 0, 'expired' => 0, 'soon' => 1][$state] ?? 2;
    }

    /**
     * Alertes d'un véhicule (papiers expirés ou bientôt, entretiens en retard ou proches).
     *
     * @return list<array{kind: string, state: string, label: string, date: ?string}>
     */
    public static function alerts(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= today();
        $alerts = [];
        $papers = $vehicle->relationLoaded('papers') ? $vehicle->papers : $vehicle->papers()->get();
        foreach (self::currentPapers($papers) as $paper) {
            $state = self::paperState($paper, $today);
            if (in_array($state, ['soon', 'expired'], true)) {
                $alerts[] = ['kind' => 'paper', 'state' => $state, 'label' => self::paperLabel($paper), 'date' => $paper->expires_on->toDateString()];
            }
        }
        foreach (self::upcoming($vehicle, $today) as $next) {
            if ($next['state'] !== 'ok') {
                $alerts[] = ['kind' => 'maintenance', 'state' => $next['state'], 'label' => $next['label'], 'date' => $next['next_on']];
            }
        }

        return $alerts;
    }

    /** Pour chaque type de papier, le plus récent (un renouvellement remplace l'ancien). */
    public static function currentPapers(iterable $papers): array
    {
        $current = [];
        foreach ($papers as $paper) {
            $key = $paper->type === 'other' ? 'other-'.$paper->id : $paper->type;
            $prev = $current[$key] ?? null;
            $date = fn ($p) => ($p->expires_on ?? $p->issued_on)?->format('Ymd') ?? '';
            if ($prev === null || $date($paper) > $date($prev) || ($date($paper) === $date($prev) && $paper->id > $prev->id)) {
                $current[$key] = $paper;
            }
        }

        return array_values($current);
    }

    public static function paperLabel(VehiclePaper $paper): string
    {
        return $paper->label ?: (self::PAPER_TYPES[$paper->type] ?? $paper->type);
    }
}
