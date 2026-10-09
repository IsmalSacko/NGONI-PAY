<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pays et opérateurs que le compte pawaPay a réellement d'actifs, lus chez
 * pawaPay (GET /v2/active-conf) et gardés six heures : un pays ou un opérateur
 * activé chez pawaPay apparaît seul, et aucun n'est annoncé qui n'existe pas.
 *
 * Seuls les pays du franc CFA sont retenus : l'abonnement est en francs CFA, et
 * le franc d'Afrique centrale (XAF) vaut celui d'Afrique de l'Ouest (XOF). Les
 * autres devises demanderaient un taux de change. Les pays servis par Jèko
 * (Côte d'Ivoire) sont écartés.
 */
class PawapayPays
{
    /** Pays de la boutique (ISO 2) → code pawaPay (ISO 3), zone franc CFA. */
    public const ISO3 = [
        'SN' => 'SEN', 'BJ' => 'BEN', 'BF' => 'BFA', 'TG' => 'TGO', 'NE' => 'NER', 'ML' => 'MLI',
        'CI' => 'CIV', 'GW' => 'GNB', 'CM' => 'CMR', 'CG' => 'COG', 'GA' => 'GAB', 'TD' => 'TCD',
        'CF' => 'CAF', 'GQ' => 'GNQ',
    ];

    public const CFA = ['XOF', 'XAF'];

    /** @return array<string, array{code: string, devise: string, moyens: array<string, string>}> */
    public function actifs(): array
    {
        $garde = Cache::get('pawapay-pays-actifs');
        if (is_array($garde)) {
            return $garde;
        }

        $pays = $this->lire();
        if ($pays === null) {
            return (array) config('pawapay.pays', []);
        }

        Cache::put('pawapay-pays-actifs', $pays, now()->addHours(6));

        return $pays;
    }

    /** @return array<string, array{code: string, devise: string, moyens: array<string, string>}>|null */
    private function lire(): ?array
    {
        $base = config('pawapay.environnement') === 'sandbox' ? 'https://api.sandbox.pawapay.io' : 'https://api.pawapay.io';

        try {
            $reponse = Http::baseUrl($base)->withToken((string) config('pawapay.token'))
                ->acceptJson()->timeout(10)->get('/v2/active-conf', ['operationType' => 'DEPOSIT']);
        } catch (\Throwable $e) {
            Log::warning('pawaPay : configuration active illisible', ['erreur' => $e->getMessage()]);

            return null;
        }

        $liste = $reponse->successful() ? $reponse->json('countries') : null;
        if (! is_array($liste) || $liste === []) {
            return null;
        }

        $parIso3 = array_flip(self::ISO3);
        $exclus = (array) config('pawapay.pays_exclus', []);
        $pays = [];

        foreach ($liste as $p) {
            $iso2 = $parIso3[$p['country'] ?? ''] ?? null;
            if ($iso2 === null || in_array($iso2, $exclus, true)) {
                continue;
            }

            $moyens = [];
            $devise = null;
            foreach ($p['providers'] ?? [] as $operateur) {
                $cfa = collect($operateur['currencies'] ?? [])->first(fn ($c) => in_array($c['currency'] ?? null, self::CFA, true));
                if ($cfa === null || blank($operateur['provider'] ?? null)) {
                    continue;
                }
                $devise ??= $cfa['currency'];
                $moyens[strtolower((string) $operateur['provider'])] = self::libelle((string) ($operateur['displayName'] ?? $operateur['provider']));
            }

            if ($moyens !== [] && $devise !== null) {
                $pays[$iso2] = ['code' => (string) $p['country'], 'devise' => $devise, 'moyens' => $moyens];
            }
        }

        return $pays === [] ? null : $pays;
    }

    /** « Orange » → « Orange Money », « MTN » → « MTN MoMo » : les noms que les commerçants connaissent. */
    private static function libelle(string $nom): string
    {
        return match (strtolower($nom)) {
            'orange' => 'Orange Money',
            'mtn' => 'MTN MoMo',
            'moov' => 'Moov Money',
            'free' => 'Free Money',
            'airtel' => 'Airtel Money',
            default => $nom,
        };
    }
}
