<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Relance des demandes clients (en retard après 2 jours, dates de rappel) — toutes les heures.
// Sur alwaysdata : tâche planifiée « php artisan schedule:run » toutes les minutes (docs/DEPLOIEMENT.md).
Schedule::command('client-requests:remind')->hourly()->withoutOverlapping();

// Contrats : échéances, reconductions, périodes à facturer — chaque matin.
Schedule::command('contracts:check')->dailyAt('07:00')->withoutOverlapping();

// Parc automobile : papiers qui expirent, entretiens à prévoir
Schedule::command('fleet:check')->dailyAt('07:15')->withoutOverlapping();

// Outillage : outils à rendre demain, aujourd'hui, ou en retard (rappel tous les 3 jours)
Schedule::command('tools:remind')->dailyAt('07:30')->withoutOverlapping();

// Informations temporaires des techniciens : contenu effacé à l'expiration — toutes les 10 minutes.
Schedule::command('tech-shares:expire')->everyTenMinutes()->withoutOverlapping();

// Boîte mail des demandes (si configurée : INBOX_HOST…) — toutes les 5 minutes.
Schedule::command('inbox:fetch')->everyFiveMinutes()->withoutOverlapping();

// Sauvegardes : la base chaque nuit, la base et les fichiers chaque dimanche (gardées BACKUP_KEEP_DAYS jours).
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping()->when(fn () => now()->dayOfWeek !== 0);
Schedule::command('backup:run --files')->weeklyOn(0, '02:30')->withoutOverlapping();

// Factures impayées : paliers de relance signalés (et relances envoyées si l'envoi automatique est activé).
Schedule::command('invoices:remind')->dailyAt('08:30')->withoutOverlapping();
