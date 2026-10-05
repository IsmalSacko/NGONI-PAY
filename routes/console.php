<?php

use App\Services\BilanDuSoir;
use App\Services\BilanMensuel;
use App\Services\DiffusionAnnonces;
use App\Services\NettoyageOrphelins;
use App\Services\PaiementJeko;
use App\Services\RappelFinEssai;
use App\Services\RestaurationBoutique;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Annonces programmées de la console (campagnes, rappels) : chaque minute.
Artisan::command('ecaisse:diffuser-annonces', function () {
    $n = app(DiffusionAnnonces::class)->diffuserLesEcheances();
    if ($n > 0) {
        $this->info("{$n} annonce(s) diffusée(s).");
    }
})->purpose('Diffuse les annonces programmées arrivées à échéance');

Schedule::command('ecaisse:diffuser-annonces')->everyMinute()->withoutOverlapping();

// Paiements Mobile Money (Jèko) restés en attente : relus chez Jèko, l'abonnement
// s'active même si le commerçant n'est pas revenu et que le webhook s'est perdu.
Artisan::command('ecaisse:verifier-paiements-mobile', function () {
    $n = app(PaiementJeko::class)->verifierEnAttente();
    if ($n > 0) {
        $this->info("{$n} paiement(s) Mobile Money relu(s).");
    }
})->purpose('Relit chez Jèko les paiements d’abonnement restés en attente');

Schedule::command('ecaisse:verifier-paiements-mobile')->everyTenMinutes()->withoutOverlapping();

// Nouvelle version de l'application (MOBILE_LATEST_VERSION changée) : annoncée
// d'elle-même à tous les comptes, une seule fois par version.
Schedule::command('ecaisse:annoncer-mise-a-jour')->everyFiveMinutes()->withoutOverlapping();

// Fin d'essai dans deux jours : le propriétaire est prévenu (application,
// push, e-mail), une fois par échéance. Neuf heures à Bamako (UTC).
Artisan::command('ecaisse:rappeler-fin-essai {--simulation : Liste qui serait prévenu, sans rien envoyer}', function () {
    $rappel = app(RappelFinEssai::class);
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

Schedule::command('ecaisse:rappeler-fin-essai')->dailyAt('09:00')->withoutOverlapping();

// Bilan du soir : ventes et encaissements de la journée, poussés au
// propriétaire. Vingt heures à Bamako (UTC) : la boutique ferme, il regarde.
Artisan::command('ecaisse:bilan-du-soir', function () {
    $n = app(BilanDuSoir::class)->envoyer(now());
    if ($n > 0) {
        $this->info("Bilan du soir envoyé à {$n} propriétaire(s).");
    }
})->purpose('Envoie le bilan de la journée aux propriétaires');

// Chaque heure : le bilan part à 20 h à l'heure du pays de chaque propriétaire.
Schedule::command('ecaisse:bilan-du-soir')->hourly()->withoutOverlapping();

// Bilan du mois précédent, le 1er à 8 h : PDF par e-mail et notification.
Artisan::command('ecaisse:bilan-mensuel', function () {
    $n = app(BilanMensuel::class)->envoyer(today());
    if ($n > 0) {
        $this->info("Bilan mensuel envoyé pour {$n} boutique(s).");
    }
})->purpose('Envoie le bilan du mois précédent aux propriétaires');

Schedule::command('ecaisse:bilan-mensuel')->monthlyOn(1, '08:00')->withoutOverlapping();

// Restes de boutiques effacées sans leurs données : aperçu par défaut,
// effacement (après sauvegarde) seulement avec --confirmer.
Artisan::command('ecaisse:nettoyer-orphelins {--confirmer : Efface vraiment, après sauvegarde} {--comptes-seulement : Seulement les restes de comptes effacés, pas les boutiques}', function () {
    $nettoyage = app(NettoyageOrphelins::class);
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

// Sauvegardes des remises à zéro de boutique : gardées 30 jours pour pouvoir
// revenir en arrière, puis effacées (elles contiennent noms et téléphones des clients).
Artisan::command('ecaisse:purger-reinitialisations', function () {
    $n = app(RestaurationBoutique::class)->purger();
    if ($n > 0) {
        $this->info("{$n} sauvegarde(s) de remise à zéro effacée(s).");
    }
})->purpose('Efface les sauvegardes de remise à zéro de plus de 30 jours');

Schedule::command('ecaisse:purger-reinitialisations')->dailyAt('03:30')->withoutOverlapping();
