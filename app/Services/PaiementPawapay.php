<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement payé en ligne via pawaPay (Mobile Money du Sénégal, du Burkina
 * Faso, du Bénin).
 *
 * Même parcours que FedaPay (voir PaiementMobile) : un dépôt est créé au prix
 * majoré de la commission, le commerçant paie sur la page de pawaPay, puis le
 * dépôt est relu chez pawaPay avant toute activation — l'appel de pawaPay
 * n'est qu'un signal, jamais une preuve.
 */
class PaiementPawapay
{
    public function actif(): bool
    {
        return filled(config('pawapay.token'));
    }

    public function proposeA(?Boutique $boutique): bool
    {
        return $this->actif() && $boutique !== null && isset(config('pawapay.pays')[$boutique->pays]);
    }

    /** Opérateurs affichés à cette boutique. @return array<string, string> */
    public function moyensPour(?Boutique $boutique): array
    {
        return $this->proposeA($boutique) ? config('pawapay.pays')[$boutique->pays]['moyens'] : [];
    }

    public static function taux(?string $moyen = null): float
    {
        return (float) config('pawapay.frais_pourcentage');
    }

    /**
     * Crée la session de paiement : l'identifiant du dépôt est noté avant
     * l'appel, pour retrouver un paiement même si la réponse se perd.
     *
     * @return string l'adresse de la page de paiement pawaPay
     */
    public function creer(DemandeAbonnement $demande, string $moyen): string
    {
        $pays = config('pawapay.pays')[$demande->boutique?->pays] ?? null;
        if ($pays === null) {
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne n’est pas disponible pour votre boutique.']]);
        }
        $reference = 'NGONI-ABO-'.$demande->id;
        $demande->update(['pawapay_deposit_id' => (string) Str::uuid()]);

        try {
            $reponse = $this->client()->post('/v2/paymentpage', [
                'depositId' => $demande->pawapay_deposit_id,
                'returnUrl' => (config('pawapay.url_retour') ?: url('/paiement-abonnement')).'?reference='.$reference,
                'amountDetails' => ['amount' => (string) ($demande->montant + $demande->frais_mobile), 'currency' => $pays['devise']],
                'country' => $pays['code'],
                'reason' => 'Abonnement Ngoni Caisse',
                'language' => 'FR',
                'metadata' => [['reference' => $reference]],
            ])->throw()->json();
        } catch (\Throwable $e) {
            Log::error('pawaPay : session refusée', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne est indisponible pour le moment. Réessayez, ou envoyez une demande avec preuve de paiement.']]);
        }

        $url = (string) ($reponse['redirectUrl'] ?? '');
        if ($url === '') {
            Log::error('pawaPay : pas d’adresse de paiement', ['demande' => $demande->id]);
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne est indisponible pour le moment. Réessayez, ou envoyez une demande avec preuve de paiement.']]);
        }

        return $url;
    }

    /**
     * Statut du dépôt chez pawaPay : « payee », « refusee » ou null (en cours,
     * jamais commencé, ou illisible).
     */
    public function statut(DemandeAbonnement $demande): ?string
    {
        try {
            $reponse = $this->client()->get('/v2/deposits/'.$demande->pawapay_deposit_id)->throw()->json();
        } catch (\Throwable $e) {
            Log::warning('pawaPay : statut illisible', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 300)]);

            return null;
        }
        // Page fermée sans payer : pawaPay ne connaît pas le dépôt. Le délai le tranchera.
        if (($reponse['status'] ?? null) !== 'FOUND') {
            return null;
        }
        $depot = $reponse['data'] ?? [];

        return match ($depot['status'] ?? null) {
            // Le montant payé doit être celui que le serveur a fixé.
            'COMPLETED' => $this->montantAttendu($demande, $depot) ? 'payee' : null,
            'FAILED' => 'refusee',
            default => null,
        };
    }

    /** Appel de pawaPay : la demande du dépôt, ou null. */
    public function demandeDe(array $appel): ?DemandeAbonnement
    {
        $id = $appel['depositId'] ?? null;

        return is_string($id) && Str::isUuid($id) ? DemandeAbonnement::where('pawapay_deposit_id', $id)->first() : null;
    }

    private function montantAttendu(DemandeAbonnement $demande, array $depot): bool
    {
        $attendu = $demande->montant + $demande->frais_mobile;
        if ((int) round((float) ($depot['amount'] ?? 0)) !== $attendu || ($depot['currency'] ?? null) !== $demande->devise) {
            Log::warning('pawaPay : montant inattendu', ['demande' => $demande->id, 'montant' => $depot['amount'] ?? null, 'devise' => $depot['currency'] ?? null]);

            return false;
        }

        return true;
    }

    private function client(): PendingRequest
    {
        $base = config('pawapay.environnement') === 'sandbox' ? 'https://api.sandbox.pawapay.io' : 'https://api.pawapay.io';

        return Http::baseUrl($base)->withToken((string) config('pawapay.token'))->acceptJson()->timeout(15);
    }
}
