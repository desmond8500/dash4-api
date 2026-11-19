<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\Equipment;
use App\Models\InstalledSystem;
use App\Models\Project;
use App\Models\ReceptionReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Éléments du suivi de chantier (bâtiment, système installé, équipement, PV) : mêmes droits que
 * le chantier du projet (notes, documents, commentaires compris).
 */
class SitePartPolicy
{
    public static function projectOf(Model $part): ?Project
    {
        return match (true) {
            $part instanceof Building, $part instanceof InstalledSystem, $part instanceof ReceptionReport => $part->project,
            $part instanceof Equipment => $part->installedSystem?->project,
            default => null,
        };
    }

    public function view(User $user, Model $part): bool
    {
        $project = self::projectOf($part);

        return $project !== null && (new ProjectPolicy)->viewSite($user, $project);
    }

    public function update(User $user, Model $part): bool
    {
        $project = self::projectOf($part);

        return $project !== null && (new ProjectPolicy)->manageSite($user, $project);
    }

    public function delete(User $user, Model $part): bool
    {
        $project = self::projectOf($part);

        return $project !== null && (new ProjectPolicy)->deleteSite($user, $project);
    }
}
