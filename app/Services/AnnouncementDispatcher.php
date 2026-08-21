<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AppUpdateMail;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie une annonce à ses destinataires.
 *
 * Deux canaux, et ils ne se remplacent pas : le courriel touche ceux qui en ont
 * un — beaucoup de commerçants s'inscrivent sans —, la notification dans
 * l'application touche tout le monde, à la prochaine ouverture. Une annonce
 * envoyée par le seul courriel n'atteindrait qu'une partie de la base.
 */
class AnnouncementDispatcher
{
    public const TYPE_APP_UPDATE = 'app.update';

    /**
     * Destinataires de l'annonce.
     *
     * Tous les comptes actifs, ou ceux que l'exploitant a désignés. Les comptes
     * désactivés sont écartés : les relancer pour une mise à jour qu'ils ne
     * pourront pas utiliser n'a pas de sens.
     */
    public function recipients(Campaign $campaign): Collection
    {
        if ($campaign->audience === Campaign::AUDIENCE_SELECTED) {
            return $campaign->targets()->where('is_active', true)->get();
        }

        return $this->audienceQuery()->get();
    }

    /**
     * Base des destinataires d'une annonce générale.
     *
     * Les comptes désactivés sont écartés — les relancer pour une mise à jour
     * qu'ils ne pourront pas utiliser n'a pas de sens — et les administrateurs
     * aussi : ce sont eux qui envoient l'annonce, se l'adresser est du bruit.
     */
    public function audienceQuery()
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', '!=', User::ROLE_SYSTEM_ADMIN)
            ->orderBy('id');
    }

    /**
     * @return array{notified: int, mailed: int, failed: int}
     */
    public function dispatch(Campaign $campaign): array
    {
        $notified = 0;
        $mailed = 0;
        $failed = 0;

        foreach ($this->recipients($campaign) as $user) {
            // La notification d'abord : c'est le canal qui atteint tout le monde,
            // et il ne dépend d'aucun service extérieur.
            if ($this->notify($campaign, $user)) {
                $notified++;
            }

            $email = trim((string) $user->email);

            if ($email === '') {
                continue;
            }

            // Déjà servi lors d'un envoi précédent : on ne relance pas.
            $dejaEnvoye = CampaignSend::query()
                ->where('campaign_id', $campaign->id)
                ->where('user_id', $user->id)
                ->where('status', CampaignSend::STATUS_SENT)
                ->exists();

            if ($dejaEnvoye) {
                continue;
            }

            try {
                Mail::to($email)->send(new AppUpdateMail($user, $campaign));

                CampaignSend::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'status' => CampaignSend::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $mailed++;
            } catch (\Throwable $e) {
                Log::error("Annonce {$campaign->key} : échec d'envoi à {$email}", [
                    'error' => $e->getMessage(),
                ]);

                CampaignSend::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'status' => CampaignSend::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            // Le serveur de messagerie n'est pas une file d'attente : on espace.
            usleep(50_000);
        }

        $campaign->update([
            'status' => Campaign::STATUS_SENT,
            'last_run_at' => now(),
        ]);

        return ['notified' => $notified, 'mailed' => $mailed, 'failed' => $failed];
    }

    /**
     * Inscrit l'annonce dans les notifications du commerçant.
     *
     * Une seule par campagne et par destinataire : un envoi rejoué ne doit pas
     * empiler dix fois le même message dans sa cloche.
     */
    private function notify(Campaign $campaign, User $user): bool
    {
        $existe = AppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_APP_UPDATE)
            ->where('body', 'like', '%' . ($campaign->version ?: $campaign->key) . '%')
            ->exists();

        if ($existe) {
            return false;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'type' => self::TYPE_APP_UPDATE,
            'title' => $campaign->subject ?: 'Mise à jour disponible',
            'body' => $campaign->version
                ? "La version {$campaign->version} est disponible. " . $campaign->message
                : (string) $campaign->message,
            'route' => null,
        ]);

        return true;
    }

    /**
     * Envoie les annonces dont l'heure est venue.
     *
     * Appelée chaque minute par le planificateur : une annonce programmée pour
     * 8 h partira à 8 h, sans qu'on ait à être devant l'écran.
     *
     * @return array<string, array{notified: int, mailed: int, failed: int}>
     */
    public function dispatchDue(): array
    {
        $resultats = [];

        $dues = Campaign::query()
            ->where('type', Campaign::TYPE_APP_UPDATE)
            ->where('status', Campaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($dues as $campaign) {
            $resultats[$campaign->key] = $this->dispatch($campaign);
        }

        return $resultats;
    }
}
