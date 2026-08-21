<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AnnouncementDispatcher;
use Illuminate\Console\Command;

/**
 * Envoie les annonces programmées dont l'heure est venue.
 */
class DispatchDueAnnouncements extends Command
{
    protected $signature = 'announcements:dispatch-due';

    protected $description = "Envoie les annonces de mise à jour programmées dont l'heure est arrivée";

    public function handle(AnnouncementDispatcher $dispatcher): int
    {
        $resultats = $dispatcher->dispatchDue();

        if ($resultats === []) {
            $this->info('Aucune annonce à envoyer.');

            return self::SUCCESS;
        }

        foreach ($resultats as $cle => $bilan) {
            $this->info(sprintf(
                '%s : %d notifiés, %d courriels, %d échecs.',
                $cle,
                $bilan['notified'],
                $bilan['mailed'],
                $bilan['failed'],
            ));
        }

        return self::SUCCESS;
    }
}
