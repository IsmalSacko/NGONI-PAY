<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Annonce;
use App\Services\DiffusionAnnonces;
use Illuminate\Console\Command;

/**
 * Annonce d'elle-même chaque nouvelle version de l'application.
 *
 * Lancée par le planificateur toutes les cinq minutes : elle ne fait rien tant
 * que MOBILE_LATEST_VERSION ne dépasse pas la dernière version déjà annoncée
 * (à la main depuis la console, ou par elle). Relancée cent fois, elle
 * n'envoie donc jamais deux fois la même version, ni une version plus ancienne.
 */
class AnnoncerMiseAJour extends Command
{
    protected $signature = 'ecaisse:annoncer-mise-a-jour {--simulation : Dit ce qui serait envoyé, sans rien envoyer}';

    protected $description = 'Notifie tous les comptes quand une nouvelle version de l’application est déclarée';

    public function handle(DiffusionAnnonces $diffusion): int
    {
        $version = trim((string) config('mobile.latest_version'));
        if ($version === '') {
            return self::SUCCESS;
        }

        $derniere = $this->derniereVersionAnnoncee();
        if ($derniere !== null && version_compare($version, $derniere, '<=')) {
            if ($this->option('simulation')) {
                $this->info("Version {$version} déjà annoncée (dernière : {$derniere}) : rien à envoyer.");
            }

            return self::SUCCESS;
        }

        $nouveautes = trim((string) config('mobile.nouveautes'));
        $annonce = new Annonce([
            'type' => 'mise_a_jour',
            'titre' => 'Nouvelle version de l’application',
            'message' => $nouveautes !== ''
                ? $nouveautes
                : 'Mettez à jour l’application depuis le Play Store pour profiter des nouveautés.',
            'version' => $version,
            'lien' => (string) config('mobile.store_url'),
            'audience' => 'tous',
            'par_email' => (bool) config('mobile.annonce_par_email'),
            'statut' => 'programmee',
            'programmee_le' => now(),
        ]);

        if ($this->option('simulation')) {
            $this->info("Serait envoyé à {$diffusion->destinataires($annonce)->count()} compte(s) : « {$annonce->titre} » — {$annonce->message}");

            return self::SUCCESS;
        }

        $annonce->save();
        $r = $diffusion->diffuser($annonce);
        $this->info("Version {$version} annoncée : {$r['notifies']} notification(s), {$r['pushs']} push, {$r['emails']} e-mail(s).");

        return self::SUCCESS;
    }

    /** Plus haute version déjà annoncée (l'ordre des versions, pas celui des dates). */
    private function derniereVersionAnnoncee(): ?string
    {
        return Annonce::where('type', 'mise_a_jour')
            ->whereNotNull('version')
            ->pluck('version')
            ->reduce(fn (?string $max, string $v) => $max === null || version_compare($v, $max, '>') ? $v : $max);
    }
}
