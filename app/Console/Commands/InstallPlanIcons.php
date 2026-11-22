<?php

namespace App\Console\Commands;

use App\Support\PlanIconLibrary;
use Illuminate\Console\Command;

/** Installe la bibliothèque standard des icônes de plans (légende de l'entreprise). */
class InstallPlanIcons extends Command
{
    protected $signature = 'plan-icons:install {--reset : remet aussi les icônes standard existantes à leur forme d\'origine}';

    protected $description = 'Ajoute les icônes standard des plans qui manquent';

    public function handle(): int
    {
        $r = PlanIconLibrary::install((bool) $this->option('reset'));
        $this->info("Icônes ajoutées : {$r['created']}, remises à neuf : {$r['updated']}, déjà là : {$r['unchanged']}.");

        return self::SUCCESS;
    }
}
