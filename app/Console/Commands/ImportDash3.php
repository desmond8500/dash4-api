<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Dash3\Dash3Importer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Reprise des données de Dash3 : voir docs/REPRISE_DASH3.md.
 * Toujours commencer par un essai (--dry-run) : tout est fait puis annulé, et le rapport s'affiche.
 */
class ImportDash3 extends Command
{
    protected $signature = 'dash3:import
        {--dry-run : Essai : tout est repris puis annulé, rien n\'est écrit}
        {--files= : Dossier storage/app/public de Dash3, pour copier logos, photos, documents et plans}
        {--owner= : E-mail du compte Dash4 qui devient l\'auteur des fiches reprises (premier administrateur par défaut)}
        {--only=* : Étapes à faire (clients, contacts, brands, providers, articles, projects, site, quote_templates, quotes, invoices, flat_rates, purchases, transactions, tasks, journals, cvs, attachments)}
        {--report= : Fichier où écrire le rapport complet (lignes rejetées, fichiers manquants)}';

    protected $description = 'Reprend les données de Dash3 (connexion dash3), sans doublon : relançable';

    public function handle(): int
    {
        $owner = $this->option('owner')
            ? User::query()->where('email', $this->option('owner'))->first()
            : User::role('admin')->where('is_active', true)->orderBy('id')->first();
        if (! $owner) {
            $this->error('Compte Dash4 introuvable : précisez --owner=e-mail d\'un compte existant.');

            return self::FAILURE;
        }

        $only = array_values(array_filter((array) $this->option('only')));
        if ($unknown = array_diff($only, Dash3Importer::STEPS)) {
            $this->error('Étape inconnue : '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $files = $this->option('files');
        if ($files && ! is_dir($files)) {
            $this->error("Dossier des fichiers introuvable : {$files}");

            return self::FAILURE;
        }

        try {
            DB::connection('dash3')->getPdo();
        } catch (Throwable $e) {
            $this->error('Connexion à la base Dash3 impossible : '.$e->getMessage());
            $this->line('Renseignez DASH3_DB_DATABASE (et au besoin DASH3_DB_HOST, DASH3_DB_USERNAME, DASH3_DB_PASSWORD) dans le .env.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? 'Essai de reprise' : 'Reprise').' de Dash3 — auteur des fiches : '.$owner->email.($files ? '' : ' — sans les fichiers (--files absent)'));

        $importer = new Dash3Importer($owner, $files, copyFiles: ! $dry);
        $report = null;
        try {
            DB::transaction(function () use ($importer, $only, $dry, &$report) {
                $report = $importer->run($only);
                if ($dry) {
                    throw new RuntimeException('dry-run');
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'dry-run') {
                throw $e;
            }
        }

        $this->printReport($report, $dry);

        return self::SUCCESS;
    }

    private function printReport(array $report, bool $dry): void
    {
        $this->newLine();
        $this->table(['Table Dash4', 'Créées', 'Déjà reprises', 'Rejetées'], collect($report['counts'])
            ->map(fn ($c, $t) => [$t, $c['created'], $c['existing'], $c['rejected']])->values()->all());

        foreach ($report['checks'] as $label => $value) {
            $this->line(sprintf('%-50s %s', $label, number_format($value, 0, ',', ' ')));
        }
        $this->line('Fichiers copiés : '.($dry ? '0 (essai)' : $report['files_copied']));

        if ($report['rejected']) {
            $this->warn(count($report['rejected']).' ligne(s) rejetée(s) :');
            foreach (array_slice($report['rejected'], 0, 30) as $line) {
                $this->line("  - {$line}");
            }
        }
        if ($report['missing_files']) {
            $this->warn(count($report['missing_files']).' fichier(s) introuvable(s) :');
            foreach (array_slice($report['missing_files'], 0, 15) as $line) {
                $this->line("  - {$line}");
            }
        }
        if (($path = $this->option('report')) !== null) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line("Rapport complet : {$path}");
        }
        $this->newLine();
        $this->info($dry ? 'Essai terminé : rien n\'a été écrit. Relancez sans --dry-run pour reprendre les données.' : 'Reprise terminée. Elle peut être relancée : seules les nouvelles fiches de Dash3 seront ajoutées.');
    }
}
