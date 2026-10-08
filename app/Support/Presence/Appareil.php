<?php

declare(strict_types=1);

namespace App\Support\Presence;

use Illuminate\Http\Request;

/**
 * L'appareil d'où vient une requête.
 *
 * L'application se présente par ses en-têtes (plateforme, modèle, version) ;
 * le back-office web, par son navigateur. Une application trop ancienne pour
 * envoyer ces en-têtes (client « Dart/… ») ne dit rien : un appareil vide,
 * qui n'efface pas ce qu'on savait déjà de celui de l'utilisateur.
 */
final readonly class Appareil
{
    public const EN_TETE_PLATEFORME = 'X-Appareil-Plateforme';

    public const EN_TETE_MODELE = 'X-Appareil-Modele';

    public const EN_TETE_VERSION = 'X-App-Version';

    /** Empreinte du téléphone (identifiant haché par l'application), pour l'essai gratuit. */
    public const EN_TETE_EMPREINTE = 'X-Appareil-Empreinte';

    /** Plateformes connues, et leur libellé dans la console. */
    public const PLATEFORMES = [
        'android' => 'Android',
        'ios' => 'iPhone',
        'windows' => 'Windows',
        'macos' => 'Mac',
        'linux' => 'Linux',
        'web' => 'Navigateur',
    ];

    public function __construct(
        public ?string $plateforme,
        public ?string $modele = null,
        public ?string $version = null,
    ) {}

    /** L'empreinte envoyée par l'application, si elle en a la forme (64 caractères hexadécimaux). */
    public static function empreinte(Request $request): ?string
    {
        $empreinte = strtolower(trim((string) $request->header(self::EN_TETE_EMPREINTE)));

        return preg_match('/^[a-f0-9]{64}$/', $empreinte) === 1 ? $empreinte : null;
    }

    public static function depuisRequete(Request $request): self
    {
        $plateforme = strtolower(trim((string) $request->header(self::EN_TETE_PLATEFORME)));
        if (array_key_exists($plateforme, self::PLATEFORMES)) {
            return new self($plateforme, self::court($request->header(self::EN_TETE_MODELE), 80), self::court($request->header(self::EN_TETE_VERSION), 20));
        }

        $agent = (string) $request->userAgent();

        return $agent === '' || str_starts_with($agent, 'Dart/') ? new self(null) : self::navigateur($agent);
    }

    public function libelle(): string
    {
        return self::PLATEFORMES[$this->plateforme] ?? 'Inconnu';
    }

    /** Le système se lit dans le navigateur ; le navigateur, lui, devient le modèle. */
    private static function navigateur(string $agent): self
    {
        $systeme = match (true) {
            str_contains($agent, 'Android') => 'android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'ios',
            str_contains($agent, 'Windows') => 'windows',
            str_contains($agent, 'Macintosh') => 'macos',
            str_contains($agent, 'Linux') => 'linux',
            default => null,
        };
        $navigateur = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };

        return new self('web', $systeme === null ? $navigateur : $navigateur.' · '.self::PLATEFORMES[$systeme]);
    }

    private static function court(?string $valeur, int $longueur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : mb_substr($valeur, 0, $longueur);
    }
}
