<?php

declare(strict_types=1);

namespace App\Services\Apple;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\AlertesExploitant;
use App\Support\ConsoleNgoni;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Abonnements achetés dans l'application iPhone (achat intégré Apple,
 * StoreKit 2, auto-renouvelables) : Apple encaisse et renouvelle, le serveur
 * suit.
 *
 * - L'application envoie l'achat qu'elle vient de faire (acheter) ; Apple
 *   envoie ensuite achats, renouvellements, fins et remboursements (notifier,
 *   App Store Server Notifications V2). Tout est un JWS vérifié (JwsApple).
 * - Chaque transaction Apple devient une fois, et une seule, une demande payée
 *   approuvée d'elle-même, comme un paiement Mobile Money confirmé : reçu,
 *   vente dans la boutique de l'exploitant, alertes. L'échéance est celle
 *   qu'Apple a vendue (expiresDate), pas une durée projetée.
 * - L'abonnement garde l'abonnement Apple qui le paie (originalTransactionId) :
 *   une fin chez Apple n'arrête que lui, jamais une période payée ensuite
 *   autrement (un paiement Mobile Money l'efface, voir AbonnementService::approuver).
 */
class AchatsApple
{
    /** Le moyen noté sur la demande. */
    public const MOYEN = 'apple_app_store';

    /** Achat (ou réabonnement) et renouvellement : la période payée est accordée. */
    private const ACCORDER = ['SUBSCRIBED', 'DID_RENEW'];

    /** Fin, paiement en échec après la grâce d'Apple, remboursement, partage familial retiré. */
    private const ARRETER = ['EXPIRED', 'GRACE_PERIOD_EXPIRED', 'REFUND', 'REVOKE'];

    public function __construct(
        private readonly JwsApple $jws,
        private readonly AbonnementService $abonnements,
    ) {}

    /** L'identifiant App Store d'une formule et d'une durée (config apple.produits), s'il y en a un. */
    public static function produitPour(string $plan, CycleFacturation $cycle): ?string
    {
        foreach ((array) config('apple.produits') as $produit => $offre) {
            if (($offre[0] ?? null) === $plan && ($offre[1] ?? null) === $cycle->value) {
                return (string) $produit;
            }
        }

        return null;
    }

    /**
     * Le jour de fin de l'abonnement (il s'arrête à 16 h ce jour-là) : celui
     * de la fin vendue par Apple, le lendemain si elle tombe après 16 h. Sans
     * quoi un achat de test (Sandbox : un mois dure cinq minutes) fait le soir
     * serait déjà fini.
     */
    public static function finPour(Carbon $expire): Carbon
    {
        $jour = $expire->copy()->startOfDay();

        return Abonnement::finA($jour)->lt($expire) ? $jour->addDay() : $jour;
    }

    /**
     * L'achat que l'application vient de faire, pour le propriétaire de la
     * boutique active : l'application l'a lié à son compte (appAccountToken).
     * Rejouer la même transaction ne fait rien de plus.
     *
     * @throws ValidationException
     */
    public function acheter(Boutique $boutique, User $demandeur, string $jws): void
    {
        try {
            $t = $this->jws->verifier($jws);
        } catch (JwsInvalide $e) {
            Log::warning('App Store : transaction refusée', ['erreur' => $e->getMessage(), 'boutique' => $boutique->id]);

            throw self::refus('Cette transaction de l’App Store est illisible ou n’est pas signée par Apple.');
        }

        $refus = $this->controler($t);
        if ($refus !== null) {
            throw self::refus($refus);
        }

        $proprietaire = $boutique->proprietaire_id === null ? null : User::find($boutique->proprietaire_id);
        if ($proprietaire === null || ! is_string($t['appAccountToken'] ?? null) || strcasecmp($t['appAccountToken'], (string) $proprietaire->id) !== 0) {
            throw self::refus('Cet achat de l’App Store a été fait depuis un autre compte Ngoni Caisse : il ne peut pas servir à celui-ci.');
        }

        $suite = $this->accorder($t, $proprietaire, $boutique, $demandeur);
        Log::info('App Store : achat envoyé par l’application '.$suite, ['transaction' => $t['transactionId'], 'environnement' => $t['environment']]);
        $message = match ($suite) {
            'remboursée' => 'Cet achat a été remboursé par Apple : il n’ouvre plus d’abonnement.',
            'expirée' => 'Cet abonnement de l’App Store est déjà terminé : renouvelez-le depuis l’application.',
            'illimité' => 'Votre abonnement est illimité : cet achat n’y change rien. Demandez son remboursement à Apple, ou écrivez au support.',
            default => null,
        };
        if ($message !== null) {
            throw self::refus($message);
        }
    }

    /**
     * Notification de l'App Store (V2). Une signature invalide lève JwsInvalide
     * (Apple réessaiera) ; tout le reste est accusé, même ignoré.
     *
     * @throws JwsInvalide
     */
    public function notifier(string $signedPayload): void
    {
        $notification = $this->jws->verifier($signedPayload);
        $type = (string) ($notification['notificationType'] ?? '');
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $journal = function (string $suite, array $t = []) use ($type, $notification): void {
            Log::info('App Store : '.$type.(filled($notification['subtype'] ?? null) ? '/'.$notification['subtype'] : '').' '.$suite, array_filter([
                'transaction' => $t['transactionId'] ?? null,
                'originale' => $t['originalTransactionId'] ?? null,
                'environnement' => $t['environment'] ?? null,
            ]));
        };

        if (! in_array($type, [...self::ACCORDER, ...self::ARRETER], true)) {
            $journal('ignorée');

            return;
        }
        // Signée par Apple, mais pour une autre application (l'identifiant n'est donné qu'en production).
        if (($data['bundleId'] ?? null) !== config('apple.bundle_id')
            || (($data['environment'] ?? null) === 'Production' && (int) ($data['appAppleId'] ?? 0) !== (int) config('apple.app_apple_id'))) {
            $journal('ignorée : autre application');

            return;
        }
        if (! is_string($data['signedTransactionInfo'] ?? null)) {
            $journal('ignorée : sans transaction');

            return;
        }

        $t = $this->jws->verifier($data['signedTransactionInfo']);
        $refus = $this->controler($t);
        if ($refus !== null) {
            $journal('ignorée : '.$refus, $t);

            return;
        }

        if (in_array($type, self::ARRETER, true)) {
            $journal($this->arreter($t, $type), $t);

            return;
        }

        $proprietaire = $this->proprietaireDe($t);
        $journal($proprietaire === null ? 'ignorée : compte introuvable' : $this->accorder($t, $proprietaire), $t);
    }

    /** Ce qui interdit d'accorder cette transaction, ou null. @param  array<string, mixed>  $t */
    private function controler(array $t): ?string
    {
        $complete = filled($t['transactionId'] ?? null) && filled($t['originalTransactionId'] ?? null) && is_numeric($t['expiresDate'] ?? null);

        return match (true) {
            ($t['bundleId'] ?? null) !== config('apple.bundle_id') => 'Cet achat a été fait dans une autre application.',
            ! in_array($t['environment'] ?? null, (array) config('apple.environnements'), true) => 'Cet achat vient d’un environnement de l’App Store non accepté.',
            $this->offre($t) === null => 'Ce produit de l’App Store n’est pas une formule Ngoni Caisse.',
            ! $complete => 'Cette transaction de l’App Store est incomplète.',
            default => null,
        };
    }

    /**
     * La formule et la durée du produit acheté.
     *
     * @param  array<string, mixed>  $t
     * @return array{0: Plan, 1: CycleFacturation}|null
     */
    private function offre(array $t): ?array
    {
        $offre = config('apple.produits')[(string) ($t['productId'] ?? '')] ?? null;
        $plan = is_array($offre) ? Plan::parCode($offre[0] ?? null) : null;
        $cycle = is_array($offre) ? CycleFacturation::tryFrom((string) ($offre[1] ?? '')) : null;

        return $plan === null || $plan->estEssai() || $cycle === null ? null : [$plan, $cycle];
    }

    /**
     * Le compte payé : celui que l'application a lié à l'achat, sinon celui
     * dont l'abonnement est déjà porté par cet abonnement Apple.
     *
     * @param  array<string, mixed>  $t
     */
    private function proprietaireDe(array $t): ?User
    {
        $jeton = $t['appAccountToken'] ?? null;
        $proprietaire = is_string($jeton) && Str::isUuid($jeton) ? User::find(strtolower($jeton)) : null;

        return $proprietaire
            ?? Abonnement::where('apple_transaction_originale', (string) $t['originalTransactionId'])->first()?->proprietaire;
    }

    /**
     * La période payée chez Apple devient l'abonnement du compte, une seule
     * fois par transaction.
     *
     * @param  array<string, mixed>  $t
     * @return string ce qu'il s'est passé : accordé, déjà traitée, remboursée, expirée, illimité
     */
    private function accorder(array $t, User $proprietaire, ?Boutique $boutique = null, ?User $demandeur = null): string
    {
        [$plan, $cycle] = $this->offre($t);
        $transaction = (string) $t['transactionId'];
        $originale = (string) $t['originalTransactionId'];
        $expire = self::date($t['expiresDate']);
        $test = ($t['environment'] ?? null) === 'Sandbox';
        $demande = null;

        try {
            $suite = DB::transaction(function () use ($t, $proprietaire, $boutique, $demandeur, $plan, $cycle, $transaction, $originale, $expire, $test, &$demande): string {
                // L'achat envoyé par l'application et la notification d'Apple arrivent souvent ensemble : un à la fois par compte.
                User::whereKey($proprietaire->id)->lockForUpdate()->first();
                if (DemandeAbonnement::where('apple_transaction_id', $transaction)->exists()) {
                    return 'déjà traitée';
                }
                if (filled($t['revocationDate'] ?? null)) {
                    return 'remboursée';
                }
                // Une période déjà finie (achat ancien, message en retard) ne rouvre rien.
                if ($expire->isPast()) {
                    return 'expirée';
                }
                $courant = Abonnement::where('user_id', $proprietaire->id)->first();
                if ($courant !== null && $courant->est_actif && $courant->fin === null) {
                    return 'illimité';
                }

                $fin = self::finPour($expire);
                // Un renouvellement arrivé après le suivant ne raccourcit pas la période du même abonnement Apple.
                if ($courant !== null && $courant->apple_transaction_originale === $originale && $courant->fin !== null && $courant->fin->gt($fin)) {
                    $fin = $courant->fin->copy();
                }
                // Achat de test (TestFlight, vérification d'Apple) : jamais moins que
                // la période déjà ouverte. Sinon le compte démo, en essai, finissait
                // avec l'abonnement de test, au bout de quelques minutes.
                if ($test && $courant !== null && $courant->estEnCours() && $courant->fin !== null && $courant->fin->gt($fin)) {
                    $fin = $courant->fin->copy();
                }

                // Le prix de la formule, comme toute demande : ce qu'Apple a
                // facturé (autre devise, sa commission retenue) est noté à côté.
                // Achat de test ou offert : rien d'encaissé, ni vente ni recette.
                $tarif = $plan->tarif($cycle);
                $encaisse = ! $test && $tarif !== null && (! is_numeric($t['price'] ?? null) || (int) $t['price'] > 0);
                $demande = DemandeAbonnement::create([
                    'user_id' => $proprietaire->id,
                    'demande_par' => $demandeur?->id,
                    'boutique_id' => $boutique?->id ?? $proprietaire->boutiquesPossedees()->withoutGlobalScopes()->orderBy('created_at')->value('id'),
                    'plan' => $plan->code,
                    'cycle' => $cycle,
                    'mois' => $cycle->mois(),
                    'montant' => $encaisse ? $tarif->montant : 0,
                    'devise' => $tarif?->devise ?? 'XOF',
                    'moyen' => self::MOYEN,
                    'telephone_contact' => $proprietaire->phone,
                    'apple_transaction_id' => $transaction,
                    'statut' => StatutDemande::EnAttente,
                ]);
                $prix = self::prix($t);
                $note = ($test ? 'Achat de test (Sandbox) sur l’App Store' : 'Payé sur l’App Store'.($prix !== null ? " ({$prix})" : ''))
                    .", transaction {$transaction}";
                $this->abonnements->approuver($demande, null, $note, finApple: $fin, appleOriginale: $originale);

                return 'accordé';
            });
        } catch (UniqueConstraintViolationException) {
            // La même transaction enregistrée au même instant par l'autre voie.
            return 'déjà traitée';
        }

        // Pendant les essais (Sandbox), un renouvellement toutes les cinq minutes : seul l'achat est signalé.
        $renouvellement = ($t['transactionReason'] ?? null) === 'RENEWAL';
        if ($suite === 'accordé' && $demande !== null && ! ($test && $renouvellement)) {
            $demande->refresh();
            $prix = self::prix($t);
            app(AlertesExploitant::class)->envoyer(
                $renouvellement ? 'Abonnement renouvelé sur l’App Store' : 'Abonnement payé sur l’App Store',
                ($demande->boutique?->nom ?? 'Une boutique').' · '.$plan->nom.' '.mb_strtolower($cycle->libelle())
                    .($prix !== null ? ' · '.$prix : '').($test ? ' · achat de test (Sandbox)' : '')
                    .' · jusqu’au '.$demande->periode_fin?->format('d/m/Y'),
                ConsoleNgoni::demandes(),
            );
        }

        return $suite;
    }

    /**
     * Fin, remboursement ou retrait chez Apple : l'abonnement s'arrête (comme
     * une révocation), seulement s'il est encore porté par cet abonnement
     * Apple et qu'aucune période plus récente ne l'a prolongé.
     *
     * @param  array<string, mixed>  $t
     */
    private function arreter(array $t, string $type): string
    {
        // Un abonnement de test (Sandbox) s'arrête tout seul en quelques minutes :
        // rien n'est coupé (le compte démo des vérifications d'Apple garde son accès).
        if (($t['environment'] ?? null) === 'Sandbox') {
            return 'test, sans effet';
        }

        $finTransaction = self::finPour(self::date($t['expiresDate']));
        $motif = match ($type) {
            'EXPIRED' => 'Abonnement App Store terminé (non renouvelé).',
            'GRACE_PERIOD_EXPIRED' => 'Abonnement App Store : paiement en échec, délai de grâce d’Apple écoulé.',
            'REFUND' => 'Abonnement App Store remboursé par Apple.',
            default => 'Abonnement App Store retiré par Apple (partage familial).',
        };

        $suite = 'sans effet';
        foreach (Abonnement::where('apple_transaction_originale', (string) $t['originalTransactionId'])->with('proprietaire')->get() as $abonnement) {
            if (! $abonnement->estEnCours() || $abonnement->fin === null || $abonnement->fin->gt($finTransaction) || $abonnement->proprietaire === null) {
                continue;
            }
            $this->abonnements->revoquer($abonnement->proprietaire, null, $motif, 'App Store');
            $suite = 'arrêté';

            // Remboursé : la vente enregistrée chez l'exploitant est à reprendre à la main.
            if (in_array($type, ['REFUND', 'REVOKE'], true)) {
                app(AlertesExploitant::class)->envoyer('Abonnement remboursé par Apple', $abonnement->proprietaire->name.' · '.$motif, ConsoleNgoni::demandes());
            }
        }

        return $suite;
    }

    /** « 6,99 USD », « 4 000 XOF » : ce qu'Apple a facturé (prix en millièmes), s'il le dit. @param  array<string, mixed>  $t */
    private static function prix(array $t): ?string
    {
        if (! is_numeric($t['price'] ?? null) || ! is_string($t['currency'] ?? null)) {
            return null;
        }
        $montant = (int) $t['price'] / 1000;

        return number_format($montant, (int) $t['price'] % 1000 === 0 ? 0 : 2, ',', ' ').' '.$t['currency'];
    }

    /** Date Apple (millisecondes depuis 1970) à l'heure du serveur. */
    private static function date(mixed $millisecondes): Carbon
    {
        return Carbon::createFromTimestampMs((int) $millisecondes, (string) config('app.timezone'));
    }

    private static function refus(string $message): ValidationException
    {
        return ValidationException::withMessages(['signed_transaction' => [$message]]);
    }
}
