<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Annonce;
use App\Models\Appareil;
use App\Models\NotificationApp;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Diffuse une annonce : une notification dans l'application pour chaque
 * destinataire (le canal qui atteint tout le monde) et un push sur ses
 * téléphones.
 */
class DiffusionAnnonces
{
    /** @return Builder<User> */
    public function destinataires(Annonce $annonce): Builder
    {
        // L'exploitant n'est écarté que s'il n'a pas de boutique : s'il tient
        // aussi une boutique avec l'application, il est un commerçant comme les autres.
        $requete = User::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('est_admin_plateforme', false)->orWhereNotNull('boutique_id'));

        $abonnes = fn (callable $filtre) => $requete->whereIn('id', Abonnement::query()->tap($filtre)->select('user_id'));
        $enCours = fn ($q) => $q->where('est_actif', true)->where(fn ($d) => $d->whereNull('fin')->orWhereDate('fin', '>=', today()));

        return match ($annonce->audience) {
            'selection' => $requete->whereIn('id', $annonce->cibles()->select('users.id')),
            'essai', 'basic', 'pro' => $abonnes(fn ($q) => $enCours($q->where('plan', $annonce->audience))),
            'expires' => $abonnes(fn ($q) => $q->where(fn ($e) => $e->where('est_actif', false)->orWhereDate('fin', '<', today()))),
            default => $requete,
        };
    }

    /**
     * Une notification dans l'application pour chaque destinataire, et un push
     * vers ses téléphones, par lots envoyés en parallèle (des milliers de
     * comptes passent en quelques secondes). Pas d'e-mail : le quota ne suit pas.
     *
     * Reprenable : une diffusion interrompue (statut « en_cours ») repart là où
     * elle s'était arrêtée, sans renotifier ceux déjà servis dans ce tour. Un
     * verrou empêche deux diffusions simultanées de la même annonce ; null si
     * une autre est déjà en train de l'envoyer.
     *
     * @return array{notifies: int, echecs: int, pushs: int}|null
     */
    public function diffuser(Annonce $annonce): ?array
    {
        $verrou = Cache::lock("diffusion-annonce-{$annonce->id}", 30 * 60);
        if (! $verrou->get()) {
            return null;
        }

        try {
            @set_time_limit(0);
            ignore_user_abort(true);

            $annonce->refresh();
            if ($annonce->statut !== 'en_cours') {
                // Nouveau tour : ses compteurs repartent de ce qui est déjà acquis.
                $annonce->update(['statut' => 'en_cours', 'derniere_diffusion' => now()]);
            }
            $debut = $annonce->derniere_diffusion;

            $notifies = $echecs = $pushs = 0;
            $push = app(PushFirebase::class);
            $message = $annonce->version ? "Version {$annonce->version} disponible. {$annonce->message}" : $annonce->message;

            $this->destinataires($annonce)
                ->whereNotIn('id', NotificationApp::where('annonce_id', $annonce->id)->where('created_at', '>=', $debut)->select('user_id'))
                ->chunkById(200, function ($users) use ($annonce, $push, $message, &$notifies, &$echecs, &$pushs): void {
                    $envois = [];
                    $notifications = [];
                    foreach ($users as $user) {
                        $notifications[$user->id] = NotificationApp::create([
                            'user_id' => $user->id,
                            'annonce_id' => $annonce->id,
                            'type' => $annonce->type,
                            'titre' => $annonce->titre,
                            'message' => $message,
                            'lien' => $annonce->lien,
                        ]);
                    }

                    foreach (Appareil::whereIn('user_id', array_keys($notifications))->get(['user_id', 'jeton']) as $appareil) {
                        $n = $notifications[$appareil->user_id];
                        $envois[] = ['jeton' => $appareil->jeton, 'donnees' => [
                            'notification_id' => (string) $n->id,
                            'titre' => $n->titre,
                            'message' => $n->message,
                            'lien' => (string) $n->lien,
                            'type' => $n->type,
                        ]];
                    }
                    $r = $push->envoyerEnMasse($envois);

                    $notifies += count($notifications);
                    $pushs += $r['reussis'];
                    $echecs += $r['echecs'];
                    // La console suit l'avancement lot après lot.
                    $annonce->increment('nb_notifies', count($notifications), ['nb_echecs' => $annonce->nb_echecs + $r['echecs']]);
                });

            $prochaine = match ($annonce->recurrence) {
                'hebdomadaire' => now()->addWeek(),
                'mensuelle' => now()->addMonth(),
                default => null,
            };
            $annonce->update(['statut' => $prochaine ? 'programmee' : 'envoyee', 'programmee_le' => $prochaine]);

            return ['notifies' => $notifies, 'echecs' => $echecs, 'pushs' => $pushs];
        } finally {
            $verrou->release();
        }
    }

    /** Annonces programmées arrivées à échéance (appelé chaque minute). */
    public function diffuserLesEcheances(): int
    {
        // Les diffusions interrompues (« en_cours ») reprennent aussi.
        $dues = Annonce::where(fn ($q) => $q->where('statut', 'en_cours')
            ->orWhere(fn ($p) => $p->where('statut', 'programmee')->whereNotNull('programmee_le')->where('programmee_le', '<=', now())))
            ->orderBy('id')->get();

        return $dues->filter(fn (Annonce $annonce) => $this->diffuser($annonce) !== null)->count();
    }
}
