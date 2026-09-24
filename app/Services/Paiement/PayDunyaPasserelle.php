<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\FournisseurPaiement;
use App\Enums\StatutPaiement;
use App\Models\Paiement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayDunya « checkout-invoice » : le client règle sur la page hébergée
 * (cartes Visa/Mastercard, et selon le compte, mobile money).
 *
 * Le montant est déjà en FCFA. Le total de la facture est celui calculé par le
 * serveur — PayDunya ignore les montants des `items`, on n'en envoie donc pas.
 */
class PayDunyaPasserelle implements PasserelleDePaiement
{
    public function fournisseur(): FournisseurPaiement
    {
        return FournisseurPaiement::PayDunya;
    }

    public function estConfiguree(): bool
    {
        return filled(config('paiements.paydunya.master_key'))
            && filled(config('paiements.paydunya.private_key'))
            && filled(config('paiements.paydunya.token'));
    }

    public function mode(): string
    {
        return config('paiements.paydunya.mode') === 'live' ? 'live' : 'test';
    }

    public function montantFacture(int $montantFcfa): array
    {
        return ['devise' => 'XOF', 'montant' => (string) $montantFcfa];
    }

    public function creer(Paiement $paiement, string $urlRetour, string $urlAnnulation, string $urlNotification): SessionPaiement
    {
        $nomBoutique = $paiement->boutique->nom;

        $facture = [
            'total_amount' => $paiement->montant,
            'description' => "Achat chez {$nomBoutique}",
        ];

        $canaux = array_values(array_filter(array_map('trim', explode(',', (string) config('paiements.paydunya.canaux')))));

        if ($canaux !== []) {
            $facture['channels'] = $canaux;
        }

        $reponse = $this->appeler('POST', 'checkout-invoice/create', [
            'invoice' => $facture,
            'store' => ['name' => $nomBoutique],
            'custom_data' => ['paiement_id' => $paiement->id],
            'actions' => [
                'return_url' => $urlRetour,
                'cancel_url' => $urlAnnulation,
                'callback_url' => $urlNotification,
            ],
        ]);

        if (($reponse['response_code'] ?? null) !== '00' || blank($reponse['token'] ?? null) || blank($reponse['response_text'] ?? null)) {
            Log::warning('PayDunya : création de facture refusée', ['paiement' => $paiement->id, 'reponse' => $reponse]);

            throw new PasserelleIndisponible('PayDunya a refusé la création du paiement.');
        }

        return new SessionPaiement((string) $reponse['token'], (string) $reponse['response_text']);
    }

    public function consulter(Paiement $paiement): EtatPaiement
    {
        $reponse = $this->appeler('GET', 'checkout-invoice/confirm/'.$paiement->reference_fournisseur);

        return match ($reponse['status'] ?? null) {
            'completed' => new EtatPaiement(StatutPaiement::Confirme),
            'cancelled' => new EtatPaiement(StatutPaiement::Annule),
            'failed' => new EtatPaiement(StatutPaiement::Echoue, $reponse['fail_reason'] ?? null),
            default => new EtatPaiement(StatutPaiement::EnAttente),
        };
    }

    /**
     * Authenticité d'une notification (IPN) : PayDunya y joint le SHA-512 de
     * la clé maîtresse du compte.
     */
    public function notificationAuthentique(?string $hash): bool
    {
        $maitre = (string) config('paiements.paydunya.master_key');

        return $maitre !== '' && is_string($hash) && hash_equals(hash('sha512', $maitre), $hash);
    }

    /**
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function appeler(string $methode, string $chemin, array $corps = []): array
    {
        try {
            $reponse = $this->client()->send($methode, $chemin, $corps === [] ? [] : ['json' => $corps]);
        } catch (ConnectionException $e) {
            Log::warning('PayDunya injoignable', ['erreur' => $e->getMessage()]);

            throw new PasserelleIndisponible('PayDunya est injoignable pour le moment. Réessayez dans un instant.', 0, $e);
        }

        return $this->lire($reponse, $chemin);
    }

    /**
     * @return array<string, mixed>
     */
    private function lire(Response $reponse, string $chemin): array
    {
        if ($reponse->serverError()) {
            Log::warning('PayDunya en erreur', ['chemin' => $chemin, 'statut' => $reponse->status()]);

            throw new PasserelleIndisponible('PayDunya rencontre une erreur. Réessayez dans un instant.');
        }

        $donnees = $reponse->json();

        return is_array($donnees) ? $donnees : [];
    }

    private function client(): PendingRequest
    {
        $racine = $this->mode() === 'live'
            ? 'https://app.paydunya.com/api/v1/'
            : 'https://app.paydunya.com/sandbox-api/v1/';

        return Http::baseUrl($racine)
            ->acceptJson()
            ->timeout(20)
            ->withHeaders([
                'PAYDUNYA-MASTER-KEY' => (string) config('paiements.paydunya.master_key'),
                'PAYDUNYA-PRIVATE-KEY' => (string) config('paiements.paydunya.private_key'),
                'PAYDUNYA-TOKEN' => (string) config('paiements.paydunya.token'),
            ]);
    }
}
