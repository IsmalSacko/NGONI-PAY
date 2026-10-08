<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appareil;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifications push par Firebase Cloud Messaging (API HTTP v1).
 *
 * S'authentifie avec la clé du compte de service (FIREBASE_CREDENTIALS, un
 * fichier JSON hors du dépôt) : un jeton OAuth signé RS256, gardé 50 minutes.
 * Messages « data » en priorité haute : l'application les affiche elle-même,
 * ouverte ou fermée, et évite les doublons. Sans clé, rien n'est envoyé :
 * l'application garde sa vérification périodique.
 */
class PushFirebase
{
    /** Requêtes Firebase simultanées par lot. */
    private const LOT = 50;

    public function actif(): bool
    {
        return $this->identifiants() !== null;
    }

    /**
     * @param  array<string, string>  $donnees
     * @return int nombre d'envois réussis
     */
    public function envoyerAuxComptes(array $userIds, array $donnees): int
    {
        if (! $this->actif() || $userIds === []) {
            return 0;
        }

        $reussis = 0;
        foreach (Appareil::whereIn('user_id', $userIds)->pluck('jeton') as $jeton) {
            $reussis += $this->envoyer($jeton, $donnees) ? 1 : 0;
        }

        return $reussis;
    }

    /**
     * Beaucoup de messages d'un coup (une annonce à des milliers de comptes) :
     * par lots de 50 requêtes simultanées plutôt qu'une à une.
     *
     * @param  list<array{jeton: string, donnees: array<string, string>}>  $envois
     * @return array{reussis: int, echecs: int}
     */
    public function envoyerEnMasse(array $envois): array
    {
        $identifiants = $this->identifiants();
        if ($identifiants === null || $envois === []) {
            return ['reussis' => 0, 'echecs' => 0];
        }

        try {
            $acces = $this->jetonAcces($identifiants);
        } catch (\Throwable $e) {
            Log::warning('Push Firebase : pas de jeton d’accès', ['erreur' => $e->getMessage()]);

            return ['reussis' => 0, 'echecs' => count($envois)];
        }

        $reussis = $echecs = 0;
        foreach (array_chunk($envois, self::LOT) as $lot) {
            $reponses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $envoi) => $pool->withToken($acces)->timeout(10)->post($this->url($identifiants), $this->corps($envoi['jeton'], $envoi['donnees'])),
                $lot,
            ));

            foreach ($lot as $i => $envoi) {
                $resultat = $this->traiter($envoi['jeton'], $reponses[$i] ?? null);
                $reussis += $resultat === true ? 1 : 0;
                $echecs += $resultat === false ? 1 : 0;
            }
        }

        return ['reussis' => $reussis, 'echecs' => $echecs];
    }

    /** @param  array<string, string>  $donnees */
    public function envoyer(string $jeton, array $donnees): bool
    {
        $identifiants = $this->identifiants();
        if ($identifiants === null) {
            return false;
        }

        try {
            $reponse = Http::withToken($this->jetonAcces($identifiants))
                ->timeout(10)
                ->post($this->url($identifiants), $this->corps($jeton, $donnees));
        } catch (\Throwable $e) {
            Log::warning('Push Firebase non envoyé', ['erreur' => $e->getMessage()]);

            return false;
        }

        return $this->traiter($jeton, $reponse) === true;
    }

    private function url(array $identifiants): string
    {
        return "https://fcm.googleapis.com/v1/projects/{$identifiants['project_id']}/messages:send";
    }

    /** @param  array<string, string>  $donnees */
    private function corps(string $jeton, array $donnees): array
    {
        return [
            'message' => [
                'token' => $jeton,
                'data' => array_map('strval', $donnees),
                'android' => ['priority' => 'high'],
            ],
        ];
    }

    /**
     * true : parti ; null : appareil disparu (jeton oublié) ; false : échec.
     */
    private function traiter(string $jeton, mixed $reponse): ?bool
    {
        if (! $reponse instanceof Response) {
            Log::warning('Push Firebase non envoyé', ['erreur' => $reponse instanceof \Throwable ? $reponse->getMessage() : 'sans réponse']);

            return false;
        }

        if ($reponse->successful()) {
            return true;
        }

        // Application désinstallée ou jeton périmé : on l'oublie.
        if ($reponse->status() === 404 || str_contains($reponse->body(), 'UNREGISTERED')) {
            Appareil::where('jeton', $jeton)->delete();

            return null;
        }

        Log::warning('Push Firebase refusé', ['statut' => $reponse->status(), 'corps' => mb_substr($reponse->body(), 0, 300)]);

        return false;
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    private function identifiants(): ?array
    {
        $chemin = (string) config('services.firebase.credentials');
        if ($chemin === '' || ! is_readable($chemin)) {
            return null;
        }

        $donnees = json_decode((string) file_get_contents($chemin), true);

        return is_array($donnees) && isset($donnees['project_id'], $donnees['client_email'], $donnees['private_key']) ? $donnees : null;
    }

    private function jetonAcces(array $identifiants): string
    {
        return Cache::remember('firebase-jeton-acces', 50 * 60, function () use ($identifiants): string {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $maintenant = time();
            $entete = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $charge = $b64(json_encode([
                'iss' => $identifiants['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $maintenant,
                'exp' => $maintenant + 3600,
            ]));
            openssl_sign("{$entete}.{$charge}", $signature, $identifiants['private_key'], OPENSSL_ALGO_SHA256);

            return (string) Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$entete}.{$charge}.".$b64($signature),
            ])->throw()->json('access_token');
        });
    }
}
