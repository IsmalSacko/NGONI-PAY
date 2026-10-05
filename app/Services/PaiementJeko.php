<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement payé par Mobile Money via Jèko (Côte d'Ivoire).
 *
 * 1. Le commerçant choisit son offre et son moyen (Wave, Orange…) : une
 *    demande d'abonnement naît, et une demande de paiement chez Jèko, dont
 *    la page mène à son application Mobile Money.
 * 2. Le paiement confirmé — webhook signé, ou statut relu chez Jèko au retour
 *    du commerçant — approuve la demande : l'abonnement s'active seul.
 *
 * Le statut est toujours relu chez Jèko avant d'activer quoi que ce soit : le
 * montant y est celui que le serveur a fixé, personne ne peut le changer.
 */
class PaiementJeko
{
    public function __construct(private readonly AbonnementService $abonnements) {}

    public function actif(): bool
    {
        return filled(config('jeko.api_key')) && filled(config('jeko.api_key_id'))
            && filled(config('jeko.store_id')) && filled(config('jeko.webhook_secret'));
    }

    /** Taux des frais pour ce moyen de paiement (le taux par défaut s'il n'en a pas). */
    public static function taux(?string $moyen = null): float
    {
        return (float) (config('jeko.frais_par_moyen')[$moyen] ?? config('jeko.frais_pourcentage'));
    }

    /**
     * Frais Mobile Money pour un prix et un moyen : de quoi recevoir le prix
     * entier une fois la commission prélevée (4 000 F à 1,5 % → 61 F, payés 4 061 F ;
     * à 4 % → 167 F, payés 4 167 F).
     */
    public static function frais(int $montant, ?string $moyen = null): int
    {
        $taux = self::taux($moyen);
        if ($taux <= 0 || $taux >= 100) {
            return 0;
        }

        return (int) ceil($montant * 100 / (100 - $taux)) - $montant;
    }

    /** Proposé à cette boutique : Jèko configuré, et une boutique ivoirienne. */
    public function proposeA(?Boutique $boutique): bool
    {
        return $this->actif() && $boutique !== null && in_array($boutique->pays, config('jeko.pays'), true);
    }

    /** @return array{demande: DemandeAbonnement, redirect_url: string} */
    public function demarrer(Boutique $boutique, User $demandeur, string $plan, CycleFacturation $cycle, string $moyen): array
    {
        if (! $this->proposeA($boutique)) {
            throw ValidationException::withMessages(['moyen' => ['Le paiement Mobile Money n’est pas disponible pour votre boutique.']]);
        }
        if (! array_key_exists($moyen, config('jeko.moyens'))) {
            throw ValidationException::withMessages(['moyen' => ['Choisissez Wave, Orange Money, MTN, Moov ou Djamo.']]);
        }

        // Une tentative précédente restée en route (application fermée, paiement
        // abandonné) : payée entre-temps, elle s'active ; sinon elle est annulée.
        foreach (DemandeAbonnement::where('user_id', $boutique->proprietaire_id)->enAttente()->whereNotNull('jeko_paiement_id')->get() as $ancienne) {
            if ($this->verifier($ancienne)->statut === StatutDemande::EnAttente) {
                $ancienne->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement Mobile Money non terminé']);
            }
        }

        $demande = $this->abonnements->soumettre($boutique, $demandeur, $plan, $cycle, moyen: 'jeko_'.$moyen, prevenir: false);

        if ($demande->devise !== 'XOF') {
            $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Devise non prise en charge par Jèko']);
            throw ValidationException::withMessages(['moyen' => ['Le paiement Mobile Money n’accepte que le franc CFA (XOF).']]);
        }

        $demande->update(['frais_mobile' => self::frais($demande->montant, $moyen)]);
        $reference = 'NGONI-ABO-'.$demande->id;
        $retour = url('/paiement-abonnement').'?reference='.$reference;

        try {
            $reponse = $this->client()->post('/partner_api/payment_requests', [
                'storeId' => config('jeko.store_id'),
                'amountCents' => ($demande->montant + $demande->frais_mobile) * 100,
                'currency' => 'XOF',
                'reference' => $reference,
                'paymentDetails' => [
                    'type' => 'redirect',
                    'data' => ['paymentMethod' => $moyen, 'successUrl' => $retour.'&issue=succes', 'errorUrl' => $retour.'&issue=echec'],
                ],
            ])->throw()->json();
        } catch (\Throwable $e) {
            Log::error('Jèko : demande de paiement refusée', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
            $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement Mobile Money indisponible']);
            throw ValidationException::withMessages(['moyen' => ['Le paiement Mobile Money est indisponible pour le moment. Réessayez, ou envoyez une demande avec preuve de paiement.']]);
        }

        $demande->update(['jeko_paiement_id' => (string) $reponse['id']]);

        return ['demande' => $demande->fresh(), 'redirect_url' => (string) $reponse['redirectUrl']];
    }

    /**
     * Relit le paiement chez Jèko et en tire la conséquence : payé, la demande
     * est approuvée (l'abonnement s'active) ; refusé, elle est annulée. Sans
     * effet sur une demande déjà tranchée : un webhook rejoué n'active rien deux fois.
     */
    public function verifier(DemandeAbonnement $demande): DemandeAbonnement
    {
        if ($demande->jeko_paiement_id === null || $demande->statut->estTranchee()) {
            return $demande;
        }

        try {
            $paiement = $this->client()->get('/partner_api/payment_requests/'.$demande->jeko_paiement_id)->throw()->json();
        } catch (\Throwable $e) {
            Log::warning('Jèko : statut illisible', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 300)]);

            return $demande;
        }

        $statut = $paiement['status'] ?? 'pending';
        if ($statut === 'pending') {
            return $demande;
        }

        return DB::transaction(function () use ($demande, $statut, $paiement): DemandeAbonnement {
            $demande = DemandeAbonnement::lockForUpdate()->findOrFail($demande->id);
            if ($demande->statut->estTranchee()) {
                return $demande;
            }

            if ($statut !== 'success') {
                $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => 'Paiement Mobile Money refusé ou annulé']);

                return $demande;
            }

            $transaction = (string) ($paiement['transaction']['id'] ?? '');
            $demande->update(['jeko_transaction_id' => $transaction ?: null]);
            $moyen = config('jeko.moyens')[$paiement['paymentMethod'] ?? ''] ?? 'Mobile Money';
            $abonnement = $this->abonnements->approuver($demande, null, "Payé par {$moyen} via Jèko".($transaction ? " ({$transaction})" : ''));

            $plan = Plan::parCode($demande->plan)?->nom ?? $demande->plan;
            app(AlertesExploitant::class)->envoyer(
                'Abonnement payé par Mobile Money',
                ($demande->boutique?->nom ?? 'Une boutique').' · '.$plan.' · '.number_format($demande->montant + $demande->frais_mobile, 0, ',', ' ').' F par '.$moyen
                    .($demande->frais_mobile > 0 ? ' (dont '.number_format($demande->frais_mobile, 0, ',', ' ').' F de frais)' : '')
                    .($abonnement->fin ? ' · jusqu’au '.$abonnement->fin->format('d/m/Y') : ''),
                '/console/demandes',
            );

            return $demande->fresh();
        });
    }

    /**
     * Filet de sécurité (planifié) : les paiements restés en attente sont relus
     * chez Jèko — commerçant parti sans revenir, webhook perdu. Sans paiement
     * après le délai (30 min), la demande est annulée avec son motif, et le
     * commerçant prévenu : elle n'encombre plus la console, il peut réessayer.
     */
    public function verifierEnAttente(): int
    {
        if (! $this->actif()) {
            return 0;
        }

        $demandes = DemandeAbonnement::enAttente()->whereNotNull('jeko_paiement_id')->get();
        foreach ($demandes as $demande) {
            $demande = $this->verifier($demande);
            if ($demande->statut === StatutDemande::EnAttente && $demande->created_at->lt(now()->subMinutes((int) config('jeko.delai_minutes')))) {
                $this->annulerFauteDePaiement($demande);
            }
        }

        return $demandes->count();
    }

    private function annulerFauteDePaiement(DemandeAbonnement $demande): void
    {
        $delai = (int) config('jeko.delai_minutes');
        $motif = "Paiement Mobile Money non reçu dans les {$delai} minutes : demande annulée automatiquement.";
        $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now(), 'note_decision' => $motif]);

        $proprietaire = User::find($demande->user_id);
        if ($proprietaire !== null) {
            $plan = Plan::parCode($demande->plan)?->nom ?? $demande->plan;
            app(NotifierCompte::class)->envoyer(
                $proprietaire,
                'Paiement de l’abonnement non reçu',
                "Nous n’avons pas reçu le paiement de votre abonnement {$plan} par Mobile Money dans les {$delai} minutes : "
                    .'la demande est annulée et rien n’a été prélevé. Vous pouvez réessayer depuis la page Abonnement.',
                email: false,
            );
        }
    }

    /** Webhook : vrai seulement si la signature HMAC-SHA256 du corps brut est celle de Jèko. */
    public function signatureValide(string $corps, ?string $signature): bool
    {
        $secret = (string) config('jeko.webhook_secret');

        return $secret !== '' && filled($signature) && hash_equals(hash_hmac('sha256', $corps, $secret), strtolower(trim((string) $signature)));
    }

    /** Webhook TRANSACTION_COMPLETED : la demande qu'il concerne, revérifiée chez Jèko. */
    public function recevoir(array $transaction): void
    {
        $paiementId = $transaction['transactionDetails']['id'] ?? null;
        $reference = $transaction['transactionDetails']['reference'] ?? null;

        $demande = $paiementId !== null ? DemandeAbonnement::where('jeko_paiement_id', $paiementId)->first() : null;
        if ($demande === null && is_string($reference) && str_starts_with($reference, 'NGONI-ABO-')) {
            $demande = DemandeAbonnement::find((int) substr($reference, strlen('NGONI-ABO-')));
        }

        if ($demande !== null) {
            $this->verifier($demande);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('jeko.url'))
            ->withHeaders(['X-API-KEY' => (string) config('jeko.api_key'), 'X-API-KEY-ID' => (string) config('jeko.api_key_id')])
            ->acceptJson()
            ->timeout(15);
    }
}
