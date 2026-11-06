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
 * Prévient le propriétaire avant la fin de son essai gratuit (2 jours avant)
 * et de son abonnement payant (3 jours avant, puis la veille). Un abonnement
 * Apple se renouvelle seul : pas de rappel.
 *
 * Sans ce rappel, le commerçant découvrait la fin en voulant encaisser,
 * parfois devant un client. Le message dit ce qui s'arrête (la caisse, à 16 h
 * le dernier jour) et ce qui reste (les données, les dettes à encaisser).
 *
 * Une fois par échéance : la date rappelée est notée sur l'abonnement
 * (rappel_fin_pour, et rappel_veille_pour pour la veille). Un passage manqué
 * (serveur arrêté) se rattrape au suivant tant que l'échéance n'est pas passée.
 */
class RappelFinEssai
{
    public const JOURS_AVANT = 2;

    /** Abonnement payant : premier rappel 3 jours avant, second la veille. */
    public const JOURS_AVANT_ABONNEMENT = 3;

    public const TYPE = 'abonnement';

    public function __construct(private readonly PushFirebase $push) {}

    /**
     * Essais gratuits qui finissent sous 2 jours et n'ont pas eu leur rappel.
     *
     * @return Builder<Abonnement>
     */
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

    /**
     * Abonnements payants qui finissent sous 3 jours et n'ont pas eu leur premier rappel.
     *
     * @return Builder<Abonnement>
     */
    public function abonnesAPrevenir()
    {
        return $this->payants()
            ->whereDate('fin', '<=', today()->addDays(self::JOURS_AVANT_ABONNEMENT))
            ->where(fn ($q) => $q->whereNull('rappel_fin_pour')->orWhereColumn('rappel_fin_pour', '!=', 'fin'));
    }

    /**
     * Abonnements payants qui finissent demain (ou aujourd'hui), déjà prévenus
     * une première fois, pas encore la veille.
     *
     * @return Builder<Abonnement>
     */
    public function abonnesAPrevenirLaVeille()
    {
        return $this->payants()
            ->whereDate('fin', '<=', today()->addDay())
            ->whereColumn('rappel_fin_pour', 'fin')
            ->where(fn ($q) => $q->whereNull('rappel_veille_pour')->orWhereColumn('rappel_veille_pour', '!=', 'fin'));
    }

    /**
     * Abonnements payants en cours, avec une échéance : ni essai, ni « à vie »,
     * ni Apple (renouvelé seul).
     *
     * @return Builder<Abonnement>
     */
    private function payants()
    {
        return Abonnement::query()
            ->avecCompte()
            ->with('proprietaire')
            ->where('plan', '!=', Plan::ESSAI)
            ->where('est_actif', true)
            ->whereNotNull('fin')
            ->whereNull('apple_transaction_originale')
            ->whereDate('fin', '>=', today());
    }

    /** Envoie les rappels dus ; rend le nombre de comptes prévenus. */
    public function envoyerLesEcheances(): int
    {
        $prevenus = 0;

        // Chaque date est notée d'abord : un échec d'envoi ne doit pas faire
        // répéter le rappel chaque jour. Même forme que `fin` (AAAA-MM-JJ).
        foreach ($this->aPrevenir()->orderBy('id')->get() as $a) {
            Abonnement::whereKey($a->id)->update(['rappel_fin_pour' => $a->fin->toDateString()]);
            $prevenus += (int) $this->prevenir($a, $this->contenu($a->fin));
        }
        foreach ($this->abonnesAPrevenir()->orderBy('id')->get() as $a) {
            // Prévenu pour la première fois la veille ou le jour même : ce
            // rappel vaut aussi celui de la veille.
            $veille = $a->fin->copy()->startOfDay()->lte(today()->addDay());
            Abonnement::whereKey($a->id)->update(['rappel_fin_pour' => $a->fin->toDateString()] + ($veille ? ['rappel_veille_pour' => $a->fin->toDateString()] : []));
            $prevenus += (int) $this->prevenir($a, $this->contenuAbonnement($a));
        }
        foreach ($this->abonnesAPrevenirLaVeille()->orderBy('id')->get() as $a) {
            Abonnement::whereKey($a->id)->update(['rappel_veille_pour' => $a->fin->toDateString()]);
            $prevenus += (int) $this->prevenir($a, $this->contenuAbonnement($a));
        }

        return $prevenus;
    }

    /**
     * Notification dans l'application, push et e-mail au propriétaire.
     *
     * @param  array{titre: string, message: string}  $contenu
     */
    private function prevenir(Abonnement $abonnement, array $contenu): bool
    {
        $proprietaire = $abonnement->proprietaire;
        if ($proprietaire === null || ! $proprietaire->is_active) {
            return false;
        }
        ['titre' => $titre, 'message' => $message] = $contenu;

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
            Log::error('Rappel de fin d’abonnement : push non envoyé', ['user_id' => $proprietaire->id, 'erreur' => $e->getMessage()]);
        }

        if (filled($proprietaire->email)) {
            try {
                Mail::to($proprietaire->email)->send(new RappelFinEssaiMail($titre, $message, $proprietaire->name));
            } catch (\Throwable $e) {
                Log::error('Rappel de fin d’abonnement : e-mail non envoyé', ['user_id' => $proprietaire->id, 'erreur' => $e->getMessage()]);
            }
        }

        return true;
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
            'message' => "Votre essai gratuit de Ngoni Caisse se termine le {$date} à 16 h.\n\n"
                .'Sans abonnement ensuite, la caisse ne pourra plus encaisser. Vos ventes, vos clients et votre stock '
                ."restent consultables, et l’argent que vos clients vous doivent s’encaisse toujours.\n\n"
                .'Abonnez-vous depuis l’application (menu Abonnement) pour continuer sans interruption.',
        ];
    }

    /** @return array{titre: string, message: string} */
    public function contenuAbonnement(Abonnement $a): array
    {
        $jours = (int) today()->diffInDays($a->fin->copy()->startOfDay());
        $quand = match (true) {
            $jours <= 0 => 'aujourd’hui à 16 h',
            $jours === 1 => 'demain',
            default => "dans {$jours} jours",
        };
        $date = $a->fin->copy()->locale('fr')->translatedFormat('l j F');
        $formule = Plan::parCode($a->plan)?->nom ?? ucfirst((string) $a->plan);

        return [
            'titre' => "Votre abonnement se termine {$quand}",
            'message' => "Votre abonnement {$formule} à Ngoni Caisse se termine le {$date} à 16 h.\n\n"
                .'Renouvelez-le dès maintenant (menu Abonnement) pour continuer à vendre sans interruption. '
                .'Sans renouvellement, la caisse ne pourra plus encaisser ; vos ventes, vos clients et votre stock restent consultables.',
        ];
    }
}
