<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Annonces programmées de la console (campagnes, rappels) : chaque minute.
Artisan::command('ecaisse:diffuser-annonces', function () {
    $n = app(\App\Services\DiffusionAnnonces::class)->diffuserLesEcheances();
    if ($n > 0) {
        $this->info("{$n} annonce(s) diffusée(s).");
    }
})->purpose('Diffuse les annonces programmées arrivées à échéance');

\Illuminate\Support\Facades\Schedule::command('ecaisse:diffuser-annonces')->everyMinute()->withoutOverlapping();

// Nouvelle version de l'application (MOBILE_LATEST_VERSION changée) : annoncée
// d'elle-même à tous les comptes, une seule fois par version.
\Illuminate\Support\Facades\Schedule::command('ecaisse:annoncer-mise-a-jour')->everyFiveMinutes()->withoutOverlapping();

// Fin d'essai dans deux jours : le propriétaire est prévenu (application,
// push, e-mail), une fois par échéance. Neuf heures à Bamako (UTC).
Artisan::command('ecaisse:rappeler-fin-essai {--simulation : Liste qui serait prévenu, sans rien envoyer}', function () {
    $rappel = app(\App\Services\RappelFinEssai::class);
    if ($this->option('simulation')) {
        foreach ($rappel->aPrevenir()->get() as $a) {
            $this->line("• {$a->proprietaire->name} — essai jusqu'au {$a->fin->format('d/m/Y')}");
        }

        return;
    }
    $n = $rappel->envoyerLesEcheances();
    if ($n > 0) {
        $this->info("{$n} propriétaire(s) prévenu(s) de la fin de leur essai.");
    }
})->purpose('Prévient les propriétaires deux jours avant la fin de leur essai');

\Illuminate\Support\Facades\Schedule::command('ecaisse:rappeler-fin-essai')->dailyAt('09:00')->withoutOverlapping();

// Restes de boutiques effacées sans leurs données : aperçu par défaut,
// effacement (après sauvegarde) seulement avec --confirmer.
Artisan::command('ecaisse:nettoyer-orphelins {--confirmer : Efface vraiment, après sauvegarde} {--comptes-seulement : Seulement les restes de comptes effacés, pas les boutiques}', function () {
    $nettoyage = app(\App\Services\NettoyageOrphelins::class);
    $apercu = $nettoyage->apercu();
    $comptes = $nettoyage->restesDeComptes();
    if ($apercu === [] && $comptes === []) {
        $this->info('Aucun reste de boutique ni de compte effacé : rien à nettoyer.');

        return;
    }
    foreach ($apercu as $b) {
        $detail = collect($b['compte'])->map(fn ($n, $t) => "{$n} {$t}")->implode(', ') ?: 'aucune donnée (rôles seulement)';
        $this->line("• {$b['boutique']} : {$detail}".($b['comptes'] ? ' — comptes rattachés : '.implode(', ', $b['comptes']) : ''));
    }
    $this->line(count($apercu).' boutique(s) fantôme(s).');
    if ($comptes !== []) {
        $this->line('Restes de comptes effacés : '.collect($comptes)->map(fn ($n, $t) => "{$n} {$t}")->implode(', '));
    }
    if ($garde = $nettoyage->historiqueGarde()) {
        $this->line('Gardé (historique de boutiques existantes) : '.collect($garde)->map(fn ($n, $t) => "{$n} {$t}")->implode(', '));
    }
    if (! $this->option('confirmer')) {
        $this->comment('Aperçu seulement. Pour effacer (après sauvegarde) : --confirmer');

        return;
    }
    $r = $nettoyage->nettoyer(avecBoutiques: ! $this->option('comptes-seulement'));
    $this->info("Nettoyé : {$r['boutiques']} boutique(s), {$r['lignes']} ligne(s). Sauvegarde : storage/app/{$r['sauvegarde']}");
})->purpose('Nettoie les restes de boutiques effacées sans leurs données');
