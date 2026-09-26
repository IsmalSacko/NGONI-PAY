<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Models\Boutique;
use App\Support\Tenancy\TenantContext;

/**
 * Montants en unités mineures entières : centimes pour l'euro (250 = 2,50 €),
 * francs pour le franc CFA qui ne se subdivise pas (2500 = 2 500 F). Les
 * décimales viennent de {@see Currencies}. Sans devise précisée : celle de la
 * boutique active.
 */
class Montant
{
    /** @var array<string, string> devise par boutique, le temps d'une requête */
    private static array $devises = [];

    public static function deviseActive(): string
    {
        $id = app(TenantContext::class)->boutiqueId();
        if ($id === null) {
            return Currencies::FALLBACK;
        }

        return self::$devises[$id] ??= (string) (Boutique::find($id)?->devise ?: Currencies::FALLBACK);
    }

    /** 250 en EUR → « 2,50 » ; 2500 en XOF → « 2 500 ». */
    public static function format(int|float|null $mineur, ?string $devise = null): string
    {
        $d = Currencies::decimals($devise ?? self::deviseActive());

        return number_format(((float) $mineur) / (10 ** $d), $d, ',', ' ');
    }

    /** Saisie (« 2,5 », « 2.50 », « 2 500 ») → unités mineures, ou null. */
    public static function parse(?string $saisie, ?string $devise = null): ?int
    {
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $saisie));
        if ($texte === '' || ! is_numeric($texte)) {
            return null;
        }

        return (int) round(((float) $texte) * (10 ** Currencies::decimals($devise ?? self::deviseActive())));
    }

    /** Unités mineures → texte à éditer (« 2,50 », « 2500 »). */
    public static function saisie(int $mineur, ?string $devise = null): string
    {
        $d = Currencies::decimals($devise ?? self::deviseActive());

        return $d === 0 ? (string) $mineur : number_format($mineur / (10 ** $d), $d, ',', '');
    }

    /** Pour les tests : la devise d'une boutique a pu changer. */
    public static function oublier(): void
    {
        self::$devises = [];
    }
}
