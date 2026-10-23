<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Annonce;
use App\Models\Appareil;
use App\Models\Boutique;
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
    /**
     * Comptes visés : vus dans l'application depuis 14 jours. Au-delà, le
     * commerçant ne l'utilise plus et la notification n'est que du bruit
     * (audit du 23/10 : 69 % des annonces partaient à des comptes inactifs).
     */
    public const ACTIFS_JOURS = 14;

    /** Au plus une annonce par compte sur cette durée ; les suivantes attendent. */
    public const UNE_ANNONCE_PAR_HEURES = 20;
    /** @return Builder<User> */
    public function destinataires(Annonce $annonce): Builder
    {
        // L'exploitant n'est écarté que s'il n'a pas de boutique : s'il tient
        // aussi une boutique avec l'application, il est un commerçant comme les autres.
        $requete = User::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('est_admin_plateforme', false)->orWhereNotNull('boutique_id'));

        // Une liste choisie à la main est servie telle quelle ; une relance des
        // expirés vise justement ceux qui ne viennent plus.
        if (! in_array($annonce->audience, ['selection', 'expires'], true)) {
            $requete->where('vu_le', '>=', now()->subDays(self::ACTIFS_JOURS));
        }
        // Message et campagne parlent au commerçant, pas à ses employés (un compte
        // rattaché à la boutique d'un autre) ; une mise à jour concerne chaque
        // téléphone, employés compris.
        if ($annonce->type !== 'mise_a_jour' && $annonce->audience !== 'selection') {
            $requete->where(fn (Builder $q) => $q->whereNull('boutique_id')
                ->orWhereIn('id', Boutique::withoutGlobalScopes()->whereNotNull('proprietaire_id')->select('proprietaire_id')));
        }

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

            $this->aServir($annonce, $debut)
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

            // Des comptes ont déjà eu une annonce aujourd'hui : ils l'auront demain.
            if ($this->aServir($annonce, $debut)->exists() || $this->reportes($annonce, $debut)->exists()) {
                $annonce->update(['reprise_le' => now()->addHours(self::UNE_ANNONCE_PAR_HEURES)]);

                return ['notifies' => $notifies, 'echecs' => $echecs, 'pushs' => $pushs];
            }

            $prochaine = match ($annonce->recurrence) {
                'hebdomadaire' => now()->addWeek(),
                'mensuelle' => now()->addMonth(),
                default => null,
            };
            $annonce->update(['statut' => $prochaine ? 'programmee' : 'envoyee', 'programmee_le' => $prochaine, 'reprise_le' => null]);

            return ['notifies' => $notifies, 'echecs' => $echecs, 'pushs' => $pushs];
        } finally {
            $verrou->release();
        }
    }

    /**
     * Les destinataires pas encore servis dans ce tour et qui peuvent l'être
     * maintenant : pas d'autre annonce reçue depuis 20 h (une mise à jour
     * passe toujours, chaque téléphone doit la connaître).
     *
     * @return Builder<User>
     */
    private function aServir(Annonce $annonce, mixed $debut): Builder
    {
        $requete = $this->destinataires($annonce)
            ->whereNotIn('id', NotificationApp::where('annonce_id', $annonce->id)->where('created_at', '>=', $debut)->select('user_id'));

        return $annonce->type === 'mise_a_jour' ? $requete : $requete->whereNotIn('id', $this->dejaServis($annonce));
    }

    /** @return Builder<User> Ceux qui attendent demain, faute de place aujourd'hui. */
    private function reportes(Annonce $annonce, mixed $debut): Builder
    {
        return $annonce->type === 'mise_a_jour'
            ? User::query()->whereRaw('1 = 0')
            : $this->destinataires($annonce)
                ->whereNotIn('id', NotificationApp::where('annonce_id', $annonce->id)->where('created_at', '>=', $debut)->select('user_id'))
                ->whereIn('id', $this->dejaServis($annonce));
    }

    private function dejaServis(Annonce $annonce): Builder
    {
        return NotificationApp::query()->whereNotNull('annonce_id')->where('annonce_id', '!=', $annonce->id)
            ->where('created_at', '>=', now()->subHours(self::UNE_ANNONCE_PAR_HEURES))->select('user_id');
    }

    /** Annonces programmées arrivées à échéance (appelé chaque minute). */
    public function diffuserLesEcheances(): int
    {
        // Les diffusions interrompues (« en_cours ») reprennent aussi.
        $dues = Annonce::where(fn ($q) => $q->where(fn ($e) => $e->where('statut', 'en_cours')
            // Une annonce reportée reprend le lendemain, pas chaque minute.
            ->where(fn ($r) => $r->whereNull('reprise_le')->orWhere('reprise_le', '<=', now())))
            ->orWhere(fn ($p) => $p->where('statut', 'programmee')->whereNotNull('programmee_le')->where('programmee_le', '<=', now())))
            ->orderBy('id')->get();

        return $dues->filter(fn (Annonce $annonce) => $this->diffuser($annonce) !== null)->count();
    }
}
