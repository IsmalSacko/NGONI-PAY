<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appareil;
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
                ->post("https://fcm.googleapis.com/v1/projects/{$identifiants['project_id']}/messages:send", [
                    'message' => [
                        'token' => $jeton,
                        'data' => array_map('strval', $donnees),
                        'android' => ['priority' => 'high'],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Push Firebase non envoyé', ['erreur' => $e->getMessage()]);

            return false;
        }

        if ($reponse->successful()) {
            return true;
        }

        // Application désinstallée ou jeton périmé : on l'oublie.
        if ($reponse->status() === 404 || str_contains($reponse->body(), 'UNREGISTERED')) {
            Appareil::where('jeton', $jeton)->delete();
        } else {
            Log::warning('Push Firebase refusé', ['statut' => $reponse->status(), 'corps' => mb_substr($reponse->body(), 0, 300)]);
        }

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
