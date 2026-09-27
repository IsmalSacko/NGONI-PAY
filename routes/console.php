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

// Restes de boutiques effacées sans leurs données : aperçu par défaut,
// effacement (après sauvegarde) seulement avec --confirmer.
Artisan::command('ecaisse:nettoyer-orphelins {--confirmer : Efface vraiment, après sauvegarde}', function () {
    $nettoyage = app(\App\Services\NettoyageOrphelins::class);
    $apercu = $nettoyage->apercu();
    if ($apercu === []) {
        $this->info('Aucune boutique fantôme : rien à nettoyer.');

        return;
    }
    foreach ($apercu as $b) {
        $detail = collect($b['compte'])->map(fn ($n, $t) => "{$n} {$t}")->implode(', ') ?: 'aucune donnée (rôles seulement)';
        $this->line("• {$b['boutique']} : {$detail}".($b['comptes'] ? ' — comptes rattachés : '.implode(', ', $b['comptes']) : ''));
    }
    $this->line(count($apercu).' boutique(s) fantôme(s).');
    if (! $this->option('confirmer')) {
        $this->comment('Aperçu seulement. Pour effacer (après sauvegarde) : --confirmer');

        return;
    }
    $r = $nettoyage->nettoyer();
    $this->info("Nettoyé : {$r['boutiques']} boutique(s), {$r['lignes']} ligne(s). Sauvegarde : storage/app/{$r['sauvegarde']}");
})->purpose('Nettoie les restes de boutiques effacées sans leurs données');
