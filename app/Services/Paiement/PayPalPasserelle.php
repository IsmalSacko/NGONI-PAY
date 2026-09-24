<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\FournisseurPaiement;
use App\Enums\StatutPaiement;
use App\Models\Paiement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Orders v2 (intention CAPTURE) : le client approuve la commande sur
 * la page PayPal, puis e-caisse la capture — c'est la capture qui déplace
 * l'argent, pas l'approbation.
 *
 * PayPal n'a pas de FCFA : le total est converti en euros (arrondi au centime
 * supérieur pour que la boutique ne perde jamais la fraction de centime).
 */
class PayPalPasserelle implements PasserelleDePaiement
{
    public function fournisseur(): FournisseurPaiement
    {
        return FournisseurPaiement::PayPal;
    }

    public function estConfiguree(): bool
    {
        return filled(config('paiements.paypal.client_id')) && filled(config('paiements.paypal.secret'));
    }

    public function mode(): string
    {
        return config('paiements.paypal.mode') === 'live' ? 'live' : 'test';
    }

    public function montantFacture(int $montantFcfa): array
    {
        $taux = (float) config('paiements.paypal.taux_fcfa');
        $centimes = (int) ceil(round($montantFcfa / $taux * 100, 6));

        return [
            'devise' => (string) config('paiements.paypal.devise'),
            'montant' => number_format($centimes / 100, 2, '.', ''),
        ];
    }

    public function creer(Paiement $paiement, string $urlRetour, string $urlAnnulation, string $urlNotification): SessionPaiement
    {
        $nomBoutique = $paiement->boutique->nom;

        $reponse = $this->appeler('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $paiement->id,
                'custom_id' => $paiement->id,
                'description' => mb_substr("Achat chez {$nomBoutique}", 0, 127),
                'amount' => [
                    'currency_code' => $paiement->devise_fournisseur,
                    'value' => $paiement->montant_fournisseur,
                ],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => mb_substr($nomBoutique, 0, 127),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $urlRetour,
                'cancel_url' => $urlAnnulation,
            ]]],
        ], "creation-{$paiement->id}");

        $lien = collect($reponse['links'] ?? [])->first(fn ($l) => in_array($l['rel'] ?? null, ['payer-action', 'approve'], true));

        if (blank($reponse['id'] ?? null) || blank($lien['href'] ?? null)) {
            Log::warning('PayPal : création de commande refusée', ['paiement' => $paiement->id, 'reponse' => $reponse]);

            throw new PasserelleIndisponible('PayPal a refusé la création du paiement.');
        }

        return new SessionPaiement((string) $reponse['id'], (string) $lien['href']);
    }

    public function consulter(Paiement $paiement): EtatPaiement
    {
        $commande = $this->appeler('GET', "/v2/checkout/orders/{$paiement->reference_fournisseur}");

        // Le client a approuvé : l'argent ne bouge qu'à la capture.
        if (($commande['status'] ?? null) === 'APPROVED') {
            $commande = $this->capturer($paiement) ?? $commande;
        }

        return $this->etat($commande);
    }

    /**
     * Vérifie la signature d'un webhook auprès de PayPal. Le corps brut est
     * recopié tel quel dans la requête de vérification : le ré-encoder
     * (espaces, échappements) invaliderait la signature.
     *
     * @param  array<string, string|null>  $entetes  clés en minuscules
     */
    public function webhookAuthentique(array $entetes, string $corpsBrut): bool
    {
        $webhookId = (string) config('paiements.paypal.webhook_id');

        if ($webhookId === '' || ! $this->estConfiguree() || json_decode($corpsBrut) === null) {
            return false;
        }

        $enveloppe = json_encode([
            'auth_algo' => $entetes['paypal-auth-algo'] ?? null,
            'cert_url' => $entetes['paypal-cert-url'] ?? null,
            'transmission_id' => $entetes['paypal-transmission-id'] ?? null,
            'transmission_sig' => $entetes['paypal-transmission-sig'] ?? null,
            'transmission_time' => $entetes['paypal-transmission-time'] ?? null,
            'webhook_id' => $webhookId,
        ], JSON_UNESCAPED_SLASHES);

        $corps = substr($enveloppe, 0, -1).',"webhook_event":'.$corpsBrut.'}';

        try {
            $reponse = $this->requete()->withBody($corps, 'application/json')->post('/v1/notifications/verify-webhook-signature');
        } catch (ConnectionException) {
            return false;
        }

        return $reponse->successful() && $reponse->json('verification_status') === 'SUCCESS';
    }

    /**
     * @return array<string, mixed>|null null si la capture n'a pas pu se faire
     */
    private function capturer(Paiement $paiement): ?array
    {
        $reponse = $this->requete("capture-{$paiement->id}")
            ->withBody('{}', 'application/json')
            ->post("/v2/checkout/orders/{$paiement->reference_fournisseur}/capture");

        if ($reponse->successful()) {
            return $reponse->json();
        }

        $probleme = $reponse->json('details.0.issue');

        // Déjà capturée (webhook + tablette simultanés) : on relit l'état.
        if ($probleme === 'ORDER_ALREADY_CAPTURED') {
            return $this->appeler('GET', "/v2/checkout/orders/{$paiement->reference_fournisseur}");
        }

        if ($probleme === 'INSTRUMENT_DECLINED') {
            return ['status' => 'DECLINED'];
        }

        Log::warning('PayPal : capture refusée', ['paiement' => $paiement->id, 'statut' => $reponse->status(), 'corps' => $reponse->json()]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $commande
     */
    private function etat(array $commande): EtatPaiement
    {
        $statut = $commande['status'] ?? null;

        if ($statut === 'VOIDED') {
            return new EtatPaiement(StatutPaiement::Annule);
        }

        if ($statut === 'DECLINED') {
            return new EtatPaiement(StatutPaiement::Echoue, 'Paiement refusé par PayPal.');
        }

        if ($statut === 'COMPLETED') {
            return match ($commande['purchase_units'][0]['payments']['captures'][0]['status'] ?? null) {
                'COMPLETED' => new EtatPaiement(StatutPaiement::Confirme),
                'DECLINED', 'FAILED' => new EtatPaiement(StatutPaiement::Echoue, 'Paiement refusé par PayPal.'),
                default => new EtatPaiement(StatutPaiement::EnAttente),
            };
        }

        return new EtatPaiement(StatutPaiement::EnAttente);
    }

    /**
     * @param  array<string, mixed>|null  $corps
     * @return array<string, mixed>
     */
    private function appeler(string $methode, string $chemin, ?array $corps = null, ?string $idempotence = null): array
    {
        try {
            $requete = $this->requete($idempotence);
            $reponse = $corps === null ? $requete->send($methode, $chemin) : $requete->send($methode, $chemin, ['json' => $corps]);
        } catch (ConnectionException $e) {
            Log::warning('PayPal injoignable', ['erreur' => $e->getMessage()]);

            throw new PasserelleIndisponible('PayPal est injoignable pour le moment. Réessayez dans un instant.', 0, $e);
        }

        return $this->lire($reponse, $chemin);
    }

    /**
     * @return array<string, mixed>
     */
    private function lire(Response $reponse, string $chemin): array
    {
        if ($reponse->serverError()) {
            Log::warning('PayPal en erreur', ['chemin' => $chemin, 'statut' => $reponse->status()]);

            throw new PasserelleIndisponible('PayPal rencontre une erreur. Réessayez dans un instant.');
        }

        if ($reponse->clientError()) {
            Log::warning('PayPal a refusé la requête', ['chemin' => $chemin, 'statut' => $reponse->status(), 'corps' => $reponse->json()]);

            throw new PasserelleIndisponible('PayPal a refusé la requête.');
        }

        $donnees = $reponse->json();

        return is_array($donnees) ? $donnees : [];
    }

    private function requete(?string $idempotence = null): PendingRequest
    {
        $requete = Http::baseUrl($this->racine())
            ->withToken($this->jeton())
            ->acceptJson()
            ->timeout(20);

        return $idempotence === null ? $requete : $requete->withHeaders(['PayPal-Request-Id' => $idempotence]);
    }

    private function racine(): string
    {
        return $this->mode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Jeton OAuth2 (client_credentials), gardé en cache jusqu'à peu avant son
     * expiration pour ne pas en redemander un à chaque appel.
     */
    private function jeton(): string
    {
        $cle = 'paypal.jeton.'.$this->mode().'.'.substr(sha1((string) config('paiements.paypal.client_id')), 0, 12);

        $jeton = Cache::get($cle);

        if (is_string($jeton)) {
            return $jeton;
        }

        try {
            $reponse = Http::baseUrl($this->racine())
                ->withBasicAuth((string) config('paiements.paypal.client_id'), (string) config('paiements.paypal.secret'))
                ->asForm()
                ->acceptJson()
                ->timeout(20)
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $e) {
            throw new PasserelleIndisponible('PayPal est injoignable pour le moment. Réessayez dans un instant.', 0, $e);
        }

        if (! $reponse->successful() || blank($reponse->json('access_token'))) {
            Log::warning('PayPal : authentification refusée', ['statut' => $reponse->status()]);

            throw new PasserelleIndisponible('Connexion à PayPal impossible : vérifiez les clés API du serveur.');
        }

        $jeton = (string) $reponse->json('access_token');
        Cache::put($cle, $jeton, max(60, (int) $reponse->json('expires_in', 300) - 60));

        return $jeton;
    }
}
