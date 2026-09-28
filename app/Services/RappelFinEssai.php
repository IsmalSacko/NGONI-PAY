<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\RappelFinEssaiMail;
use App\Models\Abonnement;
use App\Models\NotificationApp;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Prévient le propriétaire deux jours avant la fin de son essai gratuit.
 *
 * Sans ce rappel, le commerçant découvrait la fin de l'essai en voulant
 * modifier un article, parfois devant un client. Le message dit ce qui
 * continue (la caisse, sur les articles du catalogue) et ce qui s'arrête,
 * pour que rien ne le surprenne.
 *
 * Une fois par échéance : la date rappelée est notée sur l'abonnement. Un
 * passage manqué (serveur arrêté) se rattrape au suivant tant que l'essai
 * court encore — le message dit alors « demain » ou « ce soir ».
 */
class RappelFinEssai
{
    public const JOURS_AVANT = 2;

    public const TYPE = 'abonnement';

    public function __construct(private readonly PushFirebase $push) {}

    /** @return Builder<Abonnement> */
    public function aPrevenir()
    {
        $aujourdhui = today();

        return Abonnement::query()
            ->avecCompte()
            ->with('proprietaire')
            ->where('plan', Plan::ESSAI)
            ->where('est_actif', true)
            ->whereDate('fin', '>=', $aujourdhui)
            ->whereDate('fin', '<=', $aujourdhui->copy()->addDays(self::JOURS_AVANT))
            ->where(fn ($q) => $q->whereNull('rappel_fin_pour')->orWhereColumn('rappel_fin_pour', '!=', 'fin'));
    }

    /** Envoie les rappels dus ; rend le nombre de comptes prévenus. */
    public function envoyerLesEcheances(): int
    {
        $prevenus = 0;

        $this->aPrevenir()->orderBy('id')->each(function (Abonnement $abonnement) use (&$prevenus): void {
            $proprietaire = $abonnement->proprietaire;

            // Noté d'abord : un échec d'envoi ne doit pas faire répéter le
            // rappel chaque jour. Ce qui échoue est journalisé.
            // Même forme que `fin` (AAAA-MM-JJ) : c'est elle qu'on compare.
            Abonnement::whereKey($abonnement->id)->update(['rappel_fin_pour' => $abonnement->fin->toDateString()]);

            if (! $proprietaire->is_active) {
                return;
            }

            ['titre' => $titre, 'message' => $message] = $this->contenu($abonnement->fin);

            $notification = NotificationApp::create([
                'user_id' => $proprietaire->id,
                'type' => self::TYPE,
                'titre' => $titre,
                'message' => $message,
                'lien' => '/abonnement',
            ]);

            try {
                $this->push->envoyerAuxComptes([$proprietaire->id], [
                    'notification_id' => (string) $notification->id,
                    'titre' => $titre,
                    'message' => $message,
                    'lien' => '/abonnement',
                    'type' => self::TYPE,
                ]);
            } catch (\Throwable $e) {
                Log::error('Rappel de fin d’essai : push non envoyé', ['user_id' => $proprietaire->id, 'erreur' => $e->getMessage()]);
            }

            if (filled($proprietaire->email)) {
                try {
                    Mail::to($proprietaire->email)->send(new RappelFinEssaiMail($titre, $message, $proprietaire->name));
                } catch (\Throwable $e) {
                    Log::error('Rappel de fin d’essai : e-mail non envoyé', ['user_id' => $proprietaire->id, 'erreur' => $e->getMessage()]);
                }
            }

            $prevenus++;
        });

        return $prevenus;
    }

    /** @return array{titre: string, message: string} */
    public function contenu(Carbon $fin): array
    {
        $jours = (int) today()->diffInDays($fin->copy()->startOfDay());
        $quand = match (true) {
            $jours <= 0 => 'ce soir',
            $jours === 1 => 'demain',
            default => "dans {$jours} jours",
        };
        $date = $fin->copy()->locale('fr')->translatedFormat('l j F');

        return [
            'titre' => "Votre essai gratuit se termine {$quand}",
            'message' => "Votre essai gratuit de Ngoni Caisse se termine le {$date} au soir.\n\n"
                .'Sans abonnement ensuite, la caisse continue : vous encaissez les articles de votre catalogue '
                .'et imprimez vos tickets. Mais vous ne pourrez plus ajouter ni modifier vos articles, vos stocks, '
                ."vos clients ou votre équipe, ni vendre hors catalogue : un article épuisé ne pourra plus être vendu.\n\n"
                .'Abonnez-vous depuis l’application (menu Abonnement) pour continuer sans interruption.',
        ];
    }
}
