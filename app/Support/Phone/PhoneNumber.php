<?php

declare(strict_types=1);

namespace App\Support\Phone;

use App\Enums\Country;

/**
 * Mise au format international des numéros de téléphone.
 *
 * Le numéro est l'identifiant de connexion : il doit s'écrire d'une seule façon
 * en base, sinon le même commerçant peut créer deux comptes — « 76008201 » et
 * « +22376008201 » — dont un seul a ses données. D'où une forme canonique unique,
 * E.164 : « + », indicatif du pays, numéro local.
 *
 * Les commerçants tapent leur numéro comme ils le disent : avec des espaces, un
 * zéro devant, parfois l'indicatif, parfois « 00 ». Tout cela ramène à la même
 * chose, et c'est le rôle de cette classe.
 *
 * Ce qu'elle ne fait pas : réécrire ce qu'elle ne comprend pas. Une saisie qui ne
 * ressemble à aucun numéro d'abonné est rendue telle quelle. Corriger au hasard
 * serait pire que ne rien faire — appliqué à une base existante, cela remplacerait
 * des identifiants de connexion par des chaînes inventées.
 */
class PhoneNumber
{
    /**
     * Longueur minimale d'un numéro d'abonné, indicatif exclu.
     *
     * Les plans de numérotation de la zone vont de sept à neuf chiffres ; six est
     * une borne basse qui laisse passer les cas particuliers sans accepter une
     * suite de zéros pour un numéro.
     */
    private const MIN_SUBSCRIBER = 6;

    /**
     * Bornes d'un numéro international complet, indicatif compris. Quinze est le
     * maximum imposé par la norme E.164.
     */
    private const MIN_INTERNATIONAL = 9;

    private const MAX_INTERNATIONAL = 15;

    /**
     * Forme canonique d'un numéro saisi, dans le pays donné.
     *
     * Un numéro déjà international est respecté : le pays sélectionné ne le
     * réécrit pas, sans quoi un client joignable à l'étranger verrait son numéro
     * corrompu.
     */
    public static function normalize(string $raw, Country $country): string
    {
        $digits = self::digits($raw);

        if ($digits === '') {
            return trim($raw);
        }

        $international = str_starts_with(trim($raw), '+');

        // « 00 » est la forme internationale composée depuis un fixe — mais
        // seulement si un indicatif connu suit. « 00000000 » est un numéro, pas
        // un appel vers le pays « 0000 ».
        if (! $international && str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);

            // Un indicatif connu ne suffit pas : « 1 » et « 7 » en sont, si bien
            // que n'importe quel numéro précédé de deux zéros passerait pour un
            // appel international. La longueur du reste doit aussi tenir debout.
            if (self::startsWithKnownCode($rest) && self::plausibleInternational($rest)) {
                $digits = $rest;
                $international = true;
            }
        }

        if ($international) {
            return self::plausibleInternational($digits) ? '+'.$digits : trim($raw);
        }

        // L'indicatif a été saisi sans le « + ».
        if (self::carriesCode($digits, $country)) {
            return self::plausibleInternational($digits) ? '+'.$digits : trim($raw);
        }

        $local = self::withoutTrunkPrefix($digits, $country);

        if (strlen($local) < self::MIN_SUBSCRIBER) {
            // Trop court pour être un abonné : on rend la saisie inchangée
            // plutôt que d'inventer un numéro.
            return trim($raw);
        }

        return '+'.$country->dialingCode().$local;
    }

    /**
     * Numéro local, indicatif retiré : ce que le commerçant reconnaît comme
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
     * Formes sous lesquelles ce numéro peut être enregistré, la plus probable
     * d'abord.
     *
     * Sert à la connexion, et à elle seule. Les comptes créés avant la sélection
     * du pays portent parfois un numéro local nu : sans cette tolérance, leurs
     * propriétaires seraient enfermés dehors du jour au lendemain. Les
     * candidatures restent bornées au pays retenu — en essayer tous ferait
     * correspondre le « 76008201 » d'un Malien au compte d'un Ivoirien.
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

    /**
     * Le numéro est-il exploitable comme identifiant de connexion ?
     *
     * Vérifié après mise en forme : un numéro qui n'a pas pu être compris reste
     * dans sa forme d'origine, et n'est donc pas au format international.
     */
    public static function isValid(string $normalised): bool
    {
        return preg_match('/^\+\d{'.self::MIN_INTERNATIONAL.','.self::MAX_INTERNATIONAL.'}$/', $normalised) === 1;
    }

    /**
     * Chiffres seuls : espaces, tirets, points, parenthèses et « + » écartés.
     */
    public static function digits(string $raw): string
    {
        return (string) preg_replace('/\D+/', '', $raw);
    }

    /**
     * Le numéro commence-t-il par l'indicatif de son pays, suivi d'assez de
     * chiffres pour former un abonné ?
     */
    private static function carriesCode(string $digits, Country $country): bool
    {
        $code = $country->dialingCode();

        return str_starts_with($digits, $code)
            && strlen($digits) - strlen($code) >= self::MIN_SUBSCRIBER;
    }

    /**
     * Les premiers chiffres désignent-ils un pays de la liste ?
     *
     * Les indicatifs vont d'un chiffre (« 1 » pour l'Amérique du Nord) à quatre.
     * Le plus long est essayé d'abord : « 1 » préfixe aussi « 1868 », et rendre
     * la main au premier indicatif reconnu couperait le numéro au mauvais
     * endroit.
     */
    private static function startsWithKnownCode(string $digits): bool
    {
        foreach (Country::dialingCodeLengths() as $length) {
            if (strlen($digits) > $length
                && Country::fromDialingCode(substr($digits, 0, $length)) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function plausibleInternational(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= self::MIN_INTERNATIONAL && $length <= self::MAX_INTERNATIONAL;
    }

    /**
     * Numéro national dégagé de son préfixe d'appel, quand il en porte un.
     *
     * Le zéro en tête est ambigu, et l'ambiguïté se règle pays par pays : en
     * France, en Allemagne ou au Nigeria, c'est le préfixe que l'on compose à
     * l'intérieur du pays et il se retire ; en Côte d'Ivoire, c'est le premier
     * chiffre du numéro et le retirer donne un abonné qui n'existe pas. Au Mali,
     * où aucun numéro ne commence par zéro, un zéro saisi est une habitude de
     * frappe — il se retire aussi.
     *
     * Voir {@see Country::trunkPrefix()} : la Russie compose le 8, l'Amérique du
     * Nord le 1 — celui-là étant aussi son indicatif, il est déjà reconnu comme
     * tel plus haut ({@see carriesCode}).
     */
    private static function withoutTrunkPrefix(string $digits, Country $country): string
    {
        $prefix = $country->trunkPrefix();

        if ($prefix === null || ! str_starts_with($digits, $prefix)) {
            return $digits;
        }

        $rest = substr($digits, strlen($prefix));

        // Un zéro se retire autant de fois qu'il se répète : aucun numéro n'en
        // porte en tête dans ces pays. Les autres préfixes ne se retirent qu'une
        // fois — « 8 » ouvre aussi des indicatifs régionaux russes.
        return $prefix === '0' ? ltrim($rest, '0') : $rest;
    }
}
