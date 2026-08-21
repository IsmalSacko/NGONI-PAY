<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Business;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Support\Money\Currencies;

/**
 * Écrit les notifications destinées aux commerçants.
 *
 * Rassemblées ici plutôt que dispersées : les mêmes décisions se prennent depuis
 * l'API d'administration et depuis le panneau web, et le commerçant doit être
 * averti de la même façon quel que soit le chemin emprunté.
 *
 * Les envois poussés (Firebase) ne sont pas branchés : la dépendance est là, la
 * clé de service aussi, mais rien ne l'utilise encore. En attendant,
 * l'application relève ces lignes à chaque ouverture — ce qui suffit à ce qu'une
 * décision ne se perde pas.
 */
class Notifier
{
    public const SUBSCRIPTION_APPROVED = 'subscription.approved';
    public const SUBSCRIPTION_REFUSED = 'subscription.refused';
    public const SUBSCRIPTION_GRANTED = 'subscription.granted';
    public const ACCOUNT_DEACTIVATED = 'account.deactivated';
    public const ACCOUNT_REACTIVATED = 'account.reactivated';

    /**
     * Destinataire d'une notification touchant un business : son propriétaire.
     */
    private function ownerOf(Business $business): ?User
    {
        return $business->owner;
    }

    public function subscriptionApproved(SubscriptionRequest $request): void
    {
        $business = $request->business;
        $owner = $this->ownerOf($business);
        if ($owner === null) return;

        $fin = $business->fresh()->subscription?->ends_at;

        $this->write(
            $owner,
            $business,
            self::SUBSCRIPTION_APPROVED,
            'Abonnement activé',
            sprintf(
                'Votre plan %s est actif%s. Merci de votre confiance.',
                strtoupper($request->plan),
                $fin ? ' jusqu\'au ' . $fin->translatedFormat('d F Y') : '',
            ),
            '/subscription/' . $business->id,
        );
    }

    public function subscriptionRefused(SubscriptionRequest $request): void
    {
        $business = $request->business;
        $owner = $this->ownerOf($business);
        if ($owner === null) return;

        $motif = $request->decision_note;

        $this->write(
            $owner,
            $business,
            self::SUBSCRIPTION_REFUSED,
            'Demande non retenue',
            sprintf(
                'Votre demande %s (%s) n\'a pas été retenue.%s',
                strtoupper($request->plan),
                Currencies::isSupported($request->currency)
                    ? number_format((float) $request->amount_due, 0, ',', ' ') . ' ' . $request->currency
                    : (string) $request->amount_due,
                $motif ? ' Motif : ' . $motif : ' Contactez le service client.',
            ),
            '/subscription/' . $business->id,
        );
    }

    public function subscriptionGranted(Business $business, string $plan): void
    {
        $owner = $this->ownerOf($business);
        if ($owner === null) return;

        $fin = $business->fresh()->subscription?->ends_at;

        $this->write(
            $owner,
            $business,
            self::SUBSCRIPTION_GRANTED,
            'Abonnement mis à jour',
            sprintf(
                'Votre plan %s a été activé par NGONI PAY%s.',
                strtoupper($plan),
                $fin ? ' jusqu\'au ' . $fin->translatedFormat('d F Y') : ' sans échéance',
            ),
            '/subscription/' . $business->id,
        );
    }

    public function accountDeactivated(User $user): void
    {
        $this->write(
            $user,
            null,
            self::ACCOUNT_DEACTIVATED,
            'Compte désactivé',
            'Votre compte a été désactivé. Contactez le service client pour le réactiver.',
            null,
        );
    }

    public function accountReactivated(User $user): void
    {
        $this->write(
            $user,
            null,
            self::ACCOUNT_REACTIVATED,
            'Compte réactivé',
            'Votre compte est de nouveau actif. Vous pouvez vous reconnecter.',
            null,
        );
    }

    private function write(
        User $user,
        ?Business $business,
        string $type,
        string $title,
        string $body,
        ?string $route,
    ): void {
        AppNotification::create([
            'user_id' => $user->id,
            'business_id' => $business?->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'route' => $route,
        ]);
    }
}
