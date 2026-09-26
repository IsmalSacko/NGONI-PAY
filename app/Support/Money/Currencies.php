<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Devises acceptées par l'API.
 *
 * Sans liste fermée, `size:3` laisse passer n'importe quelles trois lettres :
 * une faute de saisie finit affichée derrière chaque montant de l'application,
 * et sur les factures remises aux clients. Le nombre de décimales figure ici
 * aussi — les francs CFA ne se subdivisent pas, « 12 500,00 F » serait faux,
 * et le dinar koweïtien en compte trois.
 *
 * Chaque devise proposée par un pays du catalogue doit y figurer : c'est ce que
 * vérifie `CountryCatalogueTest`. La liste est le miroir de
 * `lib/core/format/currencies.dart` côté mobile — ajouter une devise demande les
 * deux.
 */
class Currencies
{
    /**
     * Code ISO => nombre de décimales.
     *
     * Les décimales suivent l'ISO 4217, à une exception assumée : l'ouguiya
     * mauritanien y vaut cinq khoums, mais aucun prix ne s'écrit ainsi.
     *
     * @var array<string, int>
     */
    private const CATALOGUE = [
        // Zone franc.
        'XOF' => 0,
        'XAF' => 0,

        // Reste de l'Afrique.
        'AOA' => 2,
        'BIF' => 0,
        'BWP' => 2,
        'CDF' => 2,
        'CVE' => 2,
        'DJF' => 0,
        'DZD' => 2,
        'EGP' => 2,
        'ERN' => 2,
        'ETB' => 2,
        'GHS' => 2,
        'GMD' => 2,
        'GNF' => 0,
        'KES' => 2,
        'KMF' => 0,
        'LRD' => 2,
        'LSL' => 2,
        'LYD' => 3,
        'MAD' => 2,
        'MGA' => 0,
        'MRU' => 2,
        'MUR' => 2,
        'MWK' => 2,
        'MZN' => 2,
        'NAD' => 2,
        'NGN' => 2,
        'RWF' => 0,
        'SCR' => 2,
        'SDG' => 2,
        'SLE' => 2,
        'SOS' => 2,
        'SSP' => 2,
        'STN' => 2,
        'SZL' => 2,
        'TND' => 3,
        'TZS' => 2,
        'UGX' => 0,
        'ZAR' => 2,
        'ZMW' => 2,

        // Europe.
        'EUR' => 2,
        'GBP' => 2,
        'CHF' => 2,
        'SEK' => 2,
        'NOK' => 2,
        'DKK' => 2,
        'PLN' => 2,
        'CZK' => 2,
        'RON' => 2,
        'TRY' => 2,
        'RUB' => 2,
        'UAH' => 2,

        // Amériques.
        'USD' => 2,
        'CAD' => 2,
        'BRL' => 2,

        // Golfe, Levant et Asie.
        'AED' => 2,
        'SAR' => 2,
        'QAR' => 2,
        'KWD' => 3,
        'LBP' => 2,
        'CNY' => 2,
        'INR' => 2,

        // Océanie.
        'AUD' => 2,
    ];

    /**
     * Devise de repli : celle de la zone où l'application est née.
     */
    public const FALLBACK = 'XOF';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function isSupported(string $code): bool
    {
        return array_key_exists(strtoupper(trim($code)), self::CATALOGUE);
    }

    public static function decimals(string $code): int
    {
        return self::CATALOGUE[strtoupper(trim($code))] ?? 2;
    }

    /**
     * Code retenu pour une saisie : le sien s'il est connu, le repli sinon.
     */
    public static function normalize(?string $code, string $fallback = self::FALLBACK): string
    {
        $normalized = strtoupper(trim((string) $code));

        return self::isSupported($normalized) ? $normalized : $fallback;
    }
}
