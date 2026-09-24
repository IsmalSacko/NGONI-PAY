<?php

declare(strict_types=1);

namespace App\Support\Phone;

use App\Enums\Country;

/**
 * Mise au format international des numéros de téléphone.
 *
 * Le numéro est l'identifiant de connexion : il doit s'écrire d'une seule
 * façon en base, sinon le même caissier peut créer deux comptes —
 * « 76908900 » et « +22376908900 » — dont un seul a ses données. D'où une
 * forme canonique unique, E.164 : « + », indicatif du pays, numéro local.
 *
 * Les utilisateurs tapent leur numéro comme ils le disent : avec des
 * espaces, un zéro devant, parfois l'indicatif, parfois « 00 ». Tout cela
 * ramène à la même chose, et c'est le rôle de cette classe.
 *
 * Ce qu'elle ne fait pas : réécrire ce qu'elle ne comprend pas. Une saisie
 * qui ne ressemble à aucun numéro d'abonné est rendue telle quelle.
 */
class PhoneNumber
{
    /**
     * Longueur minimale d'un numéro d'abonné, indicatif exclu.
     */
    private const MIN_SUBSCRIBER = 6;

    /**
     * Bornes d'un numéro international complet, indicatif compris. Quinze
     * est le maximum imposé par la norme E.164.
     */
    private const MIN_INTERNATIONAL = 9;

    private const MAX_INTERNATIONAL = 15;

    /**
     * Forme canonique d'un numéro saisi, dans le pays donné.
     */
    public static function normalize(string $raw, Country $country): string
    {
        $digits = self::digits($raw);

        if ($digits === '') {
            return trim($raw);
        }

        $international = str_starts_with(trim($raw), '+');

        if (! $international && str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);

            if (self::startsWithKnownCode($rest)) {
                $digits = $rest;
                $international = true;
            }
        }

        if ($international) {
            return self::plausibleInternational($digits) ? '+'.$digits : trim($raw);
        }

        if (self::carriesCode($digits, $country)) {
            return self::plausibleInternational($digits) ? '+'.$digits : trim($raw);
        }

        $local = self::withoutTrunkZero($digits);

        if (strlen($local) < self::MIN_SUBSCRIBER) {
            return trim($raw);
        }

        return '+'.$country->dialingCode().$local;
    }

    /**
     * Numéro local, indicatif retiré : ce que l'utilisateur reconnaît comme
     * « son » numéro, et ce qu'il tape.
     */
    public static function local(string $raw, Country $country): string
    {
        $digits = self::digits(self::normalize($raw, $country));
        $code = $country->dialingCode();

        return str_starts_with($digits, $code)
            ? substr($digits, strlen($code))
            : $digits;
    }

    /**
     * Formes sous lesquelles ce numéro peut être enregistré, la plus
     * probable d'abord. Sert à la connexion, et à elle seule.
     *
     * @return list<string>
     */
    public static function candidates(string $raw, Country $country): array
    {
        $candidates = [];

        foreach ([
            self::normalize($raw, $country),
            self::local($raw, $country),
            '0'.self::local($raw, $country),
            trim($raw),
        ] as $candidate) {
            if ($candidate !== '' && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    public static function isValid(string $normalised): bool
    {
        return preg_match('/^\+\d{'.self::MIN_INTERNATIONAL.','.self::MAX_INTERNATIONAL.'}$/', $normalised) === 1;
    }

    public static function digits(string $raw): string
    {
        return (string) preg_replace('/\D+/', '', $raw);
    }

    private static function carriesCode(string $digits, Country $country): bool
    {
        $code = $country->dialingCode();

        return str_starts_with($digits, $code)
            && strlen($digits) - strlen($code) >= self::MIN_SUBSCRIBER;
    }

    private static function startsWithKnownCode(string $digits): bool
    {
        return strlen($digits) >= 3
            && Country::fromDialingCode(substr($digits, 0, 3)) !== null;
    }

    private static function plausibleInternational(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= self::MIN_INTERNATIONAL && $length <= self::MAX_INTERNATIONAL;
    }

    /**
     * Retire le zéro de service que l'on compose à l'intérieur d'un pays.
     */
    private static function withoutTrunkZero(string $digits): string
    {
        return str_starts_with($digits, '0') ? ltrim($digits, '0') : $digits;
    }
}
