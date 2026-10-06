<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement payé en ligne via FedaPay (Mobile Money du Niger, du Sénégal,
 * du Bénin, du Togo ; carte bancaire partout).
 *
 * Même parcours que Jèko (voir PaiementMobile) : une transaction FedaPay est
 * créée au prix majoré de la commission, le commerçant paie sur la page
 * FedaPay, puis la transaction est relue chez FedaPay avant toute activation.
 */
class PaiementFedapay
{
    public function actif(): bool
    {
        return filled(config('fedapay.secret_key')) && filled(config('fedapay.webhook_secret'));
    }

    /** Moyens proposés à cette boutique : ceux de son pays, et la carte. @return array<string, string> */
    public function moyensPour(?Boutique $boutique): array
    {
        if (! $this->actif() || $boutique === null) {
            return [];
        }

        return (config('fedapay.moyens_par_pays')[$boutique->pays] ?? []) + config('fedapay.carte');
    }

    public static function taux(string $moyen): float
    {
        return (float) (config('fedapay.frais_par_moyen')[$moyen] ?? config('fedapay.frais_par_moyen.carte'));
    }

    /**
     * Crée la transaction et son lien de paiement.
     *
     * @return string l'adresse de la page de paiement FedaPay
     */
    public function creer(DemandeAbonnement $demande, string $moyen, User $demandeur): string
    {
        $reference = 'NGONI-ABO-'.$demande->id;
        $corps = [
            'description' => "Abonnement Ngoni Caisse {$demande->plan} ({$reference})",
            'amount' => $demande->montant + $demande->frais_mobile,
            'currency' => ['iso' => 'XOF'],
            'callback_url' => url('/paiement-abonnement').'?reference='.$reference,
            'custom_metadata' => ['reference' => $reference, 'demande' => $demande->id],
        ];
        // Mobile Money à code API : l'opérateur est imposé, au taux annoncé.
        // Carte, Orange Mali, Wave Sénégal… : la page FedaPay propose le choix.
        if (in_array($moyen, config('fedapay.modes_api'), true)) {
            $corps['mode'] = $moyen;
        }
        if (filled($demandeur->email)) {
            [$prenom, $nom] = array_pad(explode(' ', trim((string) $demandeur->name), 2), 2, '');
            $corps['customer'] = ['firstname' => $prenom ?: 'Client', 'lastname' => $nom ?: $prenom, 'email' => $demandeur->email];
        }

        try {
            $transaction = $this->entite($this->client()->post('/transactions', $corps)->throw()->json());
            $id = (string) ($transaction['id'] ?? '');
            $lien = $this->client()->post("/transactions/{$id}/token")->throw()->json();
        } catch (\Throwable $e) {
            Log::error('FedaPay : transaction refusée', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['moyen' => ['Le paiement en ligne est indisponible pour le moment. Réessayez, ou envoyez une demande avec preuve de paiement.']]);
        }

        $demande->update(['fedapay_transaction_id' => $id]);

        return (string) $lien['url'];
    }

    /**
     * Statut de la transaction chez FedaPay : « payee », « refusee » ou null
     * (encore en cours, ou illisible).
     */
    public function statut(DemandeAbonnement $demande): ?string
    {
        try {
            $transaction = $this->entite($this->client()->get('/transactions/'.$demande->fedapay_transaction_id)->throw()->json());
        } catch (\Throwable $e) {
            Log::warning('FedaPay : statut illisible', ['demande' => $demande->id, 'erreur' => mb_substr($e->getMessage(), 0, 300)]);

            return null;
        }

        // Le montant payé doit être celui que le serveur a fixé.
        if ((int) ($transaction['amount'] ?? 0) !== $demande->montant + $demande->frais_mobile) {
            Log::warning('FedaPay : montant inattendu', ['demande' => $demande->id, 'montant' => $transaction['amount'] ?? null]);

            return null;
        }

        return match ($transaction['status'] ?? 'pending') {
            'approved', 'transferred' => 'payee',
            'declined', 'canceled', 'refunded', 'expired' => 'refusee',
            default => null,
        };
    }

    /**
     * En-tête X-FEDAPAY-SIGNATURE (« t=horodatage,s=signature ») : HMAC-SHA256
     * de « horodatage.corps » avec le secret du webhook, et pas plus de 5 min d'écart.
     */
    public function signatureValide(string $corps, ?string $entete): bool
    {
        $secret = (string) config('fedapay.webhook_secret');
        if ($secret === '' || blank($entete)) {
            return false;
        }

        $parties = [];
        foreach (explode(',', (string) $entete) as $morceau) {
            [$cle, $valeur] = array_pad(explode('=', trim($morceau), 2), 2, '');
            $parties[$cle][] = $valeur;
        }
        $horodatage = (int) ($parties['t'][0] ?? 0);
        if ($horodatage === 0 || abs(time() - $horodatage) > 300) {
            return false;
        }

        $attendue = hash_hmac('sha256', $horodatage.'.'.$corps, $secret);
        foreach ($parties['s'] ?? [] as $signature) {
            if (hash_equals($attendue, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Webhook : la demande concernée par l'événement, ou null. */
    public function demandeDe(array $evenement): ?DemandeAbonnement
    {
        $entite = $evenement['entity'] ?? $evenement['data']['object'] ?? [];
        $id = $entite['id'] ?? null;

        return $id === null ? null : DemandeAbonnement::where('fedapay_transaction_id', (string) $id)->first();
    }

    /** L'API répond tantôt l'objet seul, tantôt enveloppé (« v1/transaction »). */
    private function entite(array $reponse): array
    {
        return $reponse['v1/transaction'] ?? $reponse['transaction'] ?? $reponse;
    }

    private function client(): PendingRequest
    {
        $base = config('fedapay.environnement') === 'sandbox' ? 'https://sandbox-api.fedapay.com/v1' : 'https://api.fedapay.com/v1';

        return Http::baseUrl($base)->withToken((string) config('fedapay.secret_key'))->acceptJson()->timeout(15);
    }
}
