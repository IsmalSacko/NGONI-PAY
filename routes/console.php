<?php

use App\Models\NotificationApp;
use App\Models\User;
use App\Services\AnnonceTutoriel;
use App\Services\BilanDuSoir;
use App\Services\BilanMensuel;
use App\Services\Coffres;
use App\Services\CommandesRestaurant;
use App\Services\DiffusionAnnonces;
use App\Services\NettoyageOrphelins;
use App\Services\PaiementMobile;
use App\Services\RappelFinEssai;
use App\Services\RestaurationBoutique;
use App\Services\SessionCaisseService;
use App\Services\SuppressionCompte;
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

// Restaurant : les commandes différées partent en cuisine 30 minutes avant leur heure.
Artisan::command('ecaisse:lancer-commandes-differees', function () {
    $n = app(CommandesRestaurant::class)->lancerDifferees(toutes: true);
    if ($n > 0) {
        $this->info("{$n} commande(s) différée(s) partie(s) en cuisine.");
    }
})->purpose('Envoie en cuisine les commandes différées dont l’heure approche');

Schedule::command('ecaisse:lancer-commandes-differees')->everyMinute()->withoutOverlapping();

// Paiements en ligne (Jèko, FedaPay) restés en attente : relus chez leur prestataire, l'abonnement
// s'active même si le commerçant n'est pas revenu et que le webhook s'est perdu.
Artisan::command('ecaisse:verifier-paiements-mobile', function () {
    $n = app(PaiementMobile::class)->verifierEnAttente();
    if ($n > 0) {
        $this->info("{$n} paiement(s) en ligne relu(s).");
    }
})->purpose('Relit chez Jèko ou FedaPay les paiements d’abonnement restés en attente');

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
Artisan::command('ecaisse:coffres', function () {
    $n = app(Coffres::class)->passageDuSoir(now());
    if ($n > 0) {
        $this->info("Versement du soir dans les coffres : {$n} boutique(s).");
    }
})->purpose('Met de côté dans les coffres la part de la journée (23 h, heure du pays)');

// Chaque heure : chaque boutique est servie à 23 h, heure de son pays ; la
// veille est rattrapée si la tâche n'a pas tourné, jamais deux fois.
Schedule::command('ecaisse:coffres')->hourly()->withoutOverlapping();

// Les nouvelles vidéos d'aide : un seul résumé par semaine, le lundi à 10 h
// (Bamako), au lieu d'une notification à chaque publication.
Artisan::command('ecaisse:resume-videos', function () {
    $auteur = User::where('est_admin_plateforme', true)->orderBy('created_at')->first();
    if ($auteur === null) {
        return;
    }
    $n = count(app(AnnonceTutoriel::class)->resumeDeLaSemaine($auteur));
    if ($n > 0) {
        $this->info("Résumé des vidéos : {$n} annonce(s).");
    }
})->purpose('Annonce en un seul message les vidéos d’aide de la semaine');
Schedule::command('ecaisse:resume-videos')->weeklyOn(1, '10:00')->withoutOverlapping();

// Caisse oubliée : rappel à 23 h, fermeture sans comptage à 4 h (heure du pays de chaque boutique).
Artisan::command('ecaisse:caisses-oubliees', function () {
    $b = app(SessionCaisseService::class)->passageHoraire(now());
    if ($b['rappels'] + $b['fermees'] > 0) {
        $this->info("Caisses oubliées : {$b['rappels']} rappel(s), {$b['fermees']} fermeture(s) automatique(s).");
    }
})->purpose('Rappelle à 23 h les caisses encore ouvertes, les ferme sans comptage à 4 h (heure du pays)');
Schedule::command('ecaisse:caisses-oubliees')->hourly()->withoutOverlapping();

// Faux comptes du robot de test de Google Play : effacés au bout d'un jour.
Artisan::command('ecaisse:purger-comptes-test', function () {
    $n = 0;
    foreach (User::where('compte_test', true)->where('created_at', '<', now()->subDay())->get() as $compte) {
        try {
            // Sans sauvegarde : un faux compte n'a rien à garder.
            app(SuppressionCompte::class)->supprimer($compte, $compte, parLuiMeme: true, sauvegarder: false);
            $n++;
        } catch (Throwable $e) {
            report($e);
        }
    }
    if ($n > 0) {
        $this->info("Comptes de test effacés : {$n}.");
    }
})->purpose('Efface les comptes créés par le robot de test de Google Play, au bout d’un jour');
Schedule::command('ecaisse:purger-comptes-test')->dailyAt('05:30')->withoutOverlapping();

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

// Notifications de la cloche : gardées 7 jours, lues ou non. Une annonce crée
// une ligne par compte : en octobre, 65 000 lignes pour 3 000 ventes, la moitié
// de la base — et neuf sur dix jamais lues, sur des boutiques qui ne s'ouvrent
// plus. Par lots, pour ne pas tenir la table verrouillée d'un seul coup.
Artisan::command('ecaisse:purger-notifications', function () {
    $limite = now()->subDays(7);
    $total = 0;
    do {
        $n = NotificationApp::where('created_at', '<', $limite)->limit(5000)->delete();
        $total += $n;
    } while ($n > 0);
    if ($total > 0) {
        $this->info("{$total} notification(s) de plus de 7 jours effacée(s).");
    }
})->purpose('Efface les notifications de plus de 7 jours');

Schedule::command('ecaisse:purger-notifications')->dailyAt('03:45')->withoutOverlapping();
