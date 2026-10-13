<?php

declare(strict_types=1);

namespace App\Services\Ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lit la facture d'un commerçant (photo ou PDF) avec Gemini et en tire les
 * réglages de Ma boutique : le modèle le plus proche (standard ou
 * distributeur) et les mentions (adresse, BP, NIU, RCCM, PSA, message). Rien
 * n'est enregistré ici : l'app préremplit le formulaire, le commerçant
 * corrige et enregistre lui-même.
 */
final class LectureFacture
{
    private const TIMEOUT = 40;

    private const CONSIGNE = <<<'TXT'
Tu lis la facture d'un commerçant d'Afrique (photo ou PDF). Rends UNIQUEMENT un objet JSON, sans texte autour, avec ces clés :
- "modele" : "distributeur" si la facture ressemble à celle d'un distributeur ou grossiste (codes articles, quantités en casiers ou colis, code client, PSA ou précompte, frais d'enlèvement ou de livraison, consignation d'emballages, ristournes), sinon "standard".
- "nom", "adresse", "telephone", "email", "boite_postale" : ceux du VENDEUR (l'émetteur de la facture), pas du client. Chaîne ou null.
- "identifiant_fiscal" : le NIF, NIU ou numéro contribuable du VENDEUR. Chaîne ou null.
- "rccm" : le RCCM du VENDEUR. Chaîne ou null.
- "psa_taux" : le taux du PSA ou précompte sur achats en pourcentage (2.5), ou null s'il n'y en a pas. S'il n'est pas écrit mais que les montants le montrent, déduis-le.
- "tva_taux" : le taux de TVA principal en pourcentage (19.25, 18), ou null.
- "message_ticket" : la phrase de fin adressée au client (« Merci de votre visite »), au plus 160 caractères, ou null.
- "libelle_marchandises" : l'intitulé du bloc qui totalise les articles s'il en a un (« Liquide nu », « Marchandises »), ou null.
- "libelle_frais" : l'intitulé des frais ajoutés aux articles (« Frais d'enlèvement », « Frais de livraison »), ou null.
- "mentions" : la liste courte, en français, des rubriques présentes sur la facture (« Code client », « NIU du client », « Frais d'enlèvement », « Consignation d'emballages », « Ristournes »…).
N'invente rien : une information absente vaut null.
TXT;

    public function configure(): bool
    {
        return (bool) config('services.gemini.key');
    }

    /**
     * @return array{modele: string, nom: ?string, adresse: ?string, telephone: ?string, email: ?string, boite_postale: ?string,
     *     identifiant_fiscal: ?string, rccm: ?string, psa_taux: ?float, tva_taux: ?float, message_ticket: ?string, mentions: list<string>}
     */
    public function lire(UploadedFile $fichier): array
    {
        if (! $this->configure()) {
            throw new RuntimeException('La lecture des factures n’est pas disponible pour le moment.');
        }

        $contenu = [
            'contents' => [[
                'parts' => [
                    ['text' => self::CONSIGNE],
                    ['inline_data' => ['mime_type' => $fichier->getMimeType() ?: 'application/pdf', 'data' => base64_encode((string) file_get_contents($fichier->getRealPath()))]],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
        ];

        $derniere = null;
        foreach (array_unique([config('services.gemini.model'), config('services.gemini.fallback_model')]) as $modele) {
            try {
                $reponse = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(5)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modele}:generateContent", $contenu)
                    ->throw();
                $texte = collect($reponse->json('candidates.0.content.parts'))->pluck('text')->implode('');

                return self::normaliser(json_decode(self::sansBalises($texte), true));
            } catch (ConnectionException|RequestException $e) {
                // Panne ou surcharge de ce modèle : le suivant.
                $derniere = $e;
                Log::warning('Lecture de facture : '.$modele.' indisponible', ['erreur' => $e->getMessage()]);
            }
        }

        throw new RuntimeException('La lecture de la facture n’a pas abouti : réessayez dans un instant.', 0, $derniere instanceof Throwable ? $derniere : null);
    }

    /** Un modèle qui entoure son JSON de ```json … ``` reste lisible. */
    private static function sansBalises(string $texte): string
    {
        return trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($texte)));
    }

    /** Seules les clés attendues, aux bons types et aux bonnes longueurs. */
    private static function normaliser(mixed $lu): array
    {
        if (! is_array($lu)) {
            throw new RuntimeException('Cette facture n’a pas pu être lue : essayez une photo plus nette.');
        }
        $texte = static function (string $cle, int $max) use ($lu): ?string {
            $v = $lu[$cle] ?? null;
            $v = is_scalar($v) ? trim((string) $v) : '';

            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $taux = static function (string $cle, float $max) use ($lu): ?float {
            $v = $lu[$cle] ?? null;
            $v = is_string($v) ? str_replace([',', '%', ' '], ['.', '', ''], $v) : $v;

            return is_numeric($v) && (float) $v > 0 && (float) $v <= $max ? round((float) $v, 2) : null;
        };

        return [
            'modele' => ($lu['modele'] ?? null) === 'distributeur' ? 'distributeur' : 'standard',
            'nom' => $texte('nom', 120),
            'adresse' => $texte('adresse', 255),
            'telephone' => $texte('telephone', 30),
            'email' => $texte('email', 120),
            'boite_postale' => $texte('boite_postale', 60),
            'identifiant_fiscal' => $texte('identifiant_fiscal', 60),
            'rccm' => $texte('rccm', 60),
            'psa_taux' => $taux('psa_taux', 20),
            'tva_taux' => $taux('tva_taux', 100),
            'message_ticket' => $texte('message_ticket', 160),
            'libelle_marchandises' => $texte('libelle_marchandises', 40),
            'libelle_frais' => $texte('libelle_frais', 40),
            'mentions' => array_values(array_slice(array_filter(
                array_map(static fn ($m) => is_scalar($m) ? mb_substr(trim((string) $m), 0, 60) : '', (array) ($lu['mentions'] ?? [])),
            ), 0, 15)),
        ];
    }
}
