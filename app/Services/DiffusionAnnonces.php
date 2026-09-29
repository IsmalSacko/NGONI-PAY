<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AnnonceMail;
use App\Models\Abonnement;
use App\Models\Annonce;
use App\Models\NotificationApp;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Diffuse une annonce : une notification dans l'application pour chaque
 * destinataire (le canal qui atteint tout le monde), et un e-mail à ceux qui
 * en ont une adresse si l'annonce le demande.
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

    /** @return array{notifies: int, emails: int, echecs: int, pushs: int} */
    public function diffuser(Annonce $annonce): array
    {
        $notifies = $emails = $echecs = $pushs = 0;
        $push = app(PushFirebase::class);

        $this->destinataires($annonce)->orderBy('id')->chunk(200, function ($users) use ($annonce, $push, &$notifies, &$emails, &$echecs, &$pushs): void {
            foreach ($users as $user) {
                $notification = NotificationApp::create([
                    'user_id' => $user->id,
                    'annonce_id' => $annonce->id,
                    'type' => $annonce->type,
                    'titre' => $annonce->titre,
                    'message' => $annonce->version ? "Version {$annonce->version} disponible. {$annonce->message}" : $annonce->message,
                    'lien' => $annonce->lien,
                ]);
                $notifies++;

                // Push instantané vers ses téléphones (si Firebase est configuré).
                $pushs += $push->envoyerAuxComptes([$user->id], [
                    'notification_id' => (string) $notification->id,
                    'titre' => $notification->titre,
                    'message' => $notification->message,
                    'lien' => (string) $notification->lien,
                    'type' => $notification->type,
                ]);

                if (! $annonce->par_email || blank($user->email)) {
                    continue;
                }

                try {
                    // Les annonces partent en nombre : sur leur propre quota
                    // (Mailjet), pour ne jamais priver une inscription d'e-mail.
                    Mail::mailer(config('mail.annonces_mailer'))->to($user->email)->send(new AnnonceMail($annonce, $user->name));
                    $emails++;
                } catch (\Throwable $e) {
                    $echecs++;
                    Log::error("Annonce {$annonce->id} : e-mail non envoyé", ['user_id' => $user->id, 'erreur' => $e->getMessage()]);
                }
            }
        });

        $prochaine = match ($annonce->recurrence) {
            'hebdomadaire' => now()->addWeek(),
            'mensuelle' => now()->addMonth(),
            default => null,
        };

        $annonce->update([
            'statut' => $prochaine ? 'programmee' : 'envoyee',
            'programmee_le' => $prochaine,
            'derniere_diffusion' => now(),
            'nb_notifies' => $annonce->nb_notifies + $notifies,
            'nb_emails' => $annonce->nb_emails + $emails,
            'nb_echecs' => $annonce->nb_echecs + $echecs,
        ]);

        return ['notifies' => $notifies, 'emails' => $emails, 'echecs' => $echecs, 'pushs' => $pushs];
    }

    /** Annonces programmées arrivées à échéance (appelé chaque minute). */
    public function diffuserLesEcheances(): int
    {
        $dues = Annonce::where('statut', 'programmee')->whereNotNull('programmee_le')->where('programmee_le', '<=', now())->get();

        foreach ($dues as $annonce) {
            $this->diffuser($annonce);
        }

        return $dues->count();
    }
}
