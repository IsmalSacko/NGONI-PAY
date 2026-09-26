<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Support\Facades\DB;

/**
 * Multiplie (ou divise) tous les montants d'une boutique : passage à une
 * devise qui a un autre nombre de décimales, sans changer la valeur affichée
 * (25 000 F → 25 000,00 €, soit 2 500 000 centimes).
 */
class Reechelonnement
{
    /** Colonnes de montants, par table rattachée à la boutique. */
    private const COLONNES = [
        'produits' => ['prix_vente', 'prix_achat'],
        'ventes' => ['sous_total', 'remise', 'tva', 'total', 'montant_recu', 'monnaie_rendue'],
        'sessions_caisse' => ['fond_initial', 'fond_final', 'ecart'],
    ];

    /**
     * Parités fixes avec l'euro (unités de la devise pour 1 €) : le franc CFA
     * y est arrimé, la conversion est donc exacte et connue d'avance.
     */
    public const PARITES_EURO = ['XOF' => 655.957, 'XAF' => 655.957, 'KMF' => 491.96775, 'CVE' => 110.265];

    /** Taux connu d'avance (unités de l'ancienne devise pour 1 de la nouvelle), sinon null. */
    public static function tauxFixe(string $ancienne, string $nouvelle): ?float
    {
        $ancienne = strtoupper($ancienne);
        $nouvelle = strtoupper($nouvelle);
        if ($ancienne === $nouvelle) {
            return 1.0;
        }
        $versEuro = fn (string $d) => $d === 'EUR' ? 1.0 : (self::PARITES_EURO[$d] ?? null);
        $a = $versEuro($ancienne);
        $n = $versEuro($nouvelle);

        return $a === null || $n === null ? null : $a / $n;
    }

    /**
     * Passage d'une devise à l'autre. Avec un taux (unités de l'ancienne pour
     * 1 de la nouvelle) : conversion, 300 F → 0,46 €. Sans taux : même valeur
     * affichée, 300 F → 300,00 €.
     */
    public static function entreDevises(string $boutiqueId, string $ancienne, string $nouvelle, ?float $taux = null): void
    {
        $ecart = Currencies::decimals($nouvelle) - Currencies::decimals($ancienne);
        $facteur = (10 ** $ecart) / ($taux ?? 1.0);
        if (abs($facteur - 1.0) > 1e-12) {
            self::multiplier($boutiqueId, $facteur);
        }
    }

    /** Multiplie tous les montants de la boutique par un facteur quelconque, arrondi à l'unité mineure. */
    public static function multiplier(string $boutiqueId, float $facteur): void
    {
        $f = rtrim(rtrim(number_format($facteur, 12, '.', ''), '0'), '.');
        $expression = fn (string $col) => DB::raw("ROUND({$col} * {$f})");

        DB::transaction(function () use ($boutiqueId, $expression): void {
            foreach (self::COLONNES as $table => $colonnes) {
                DB::table($table)->where('boutique_id', $boutiqueId)
                    ->update(array_combine($colonnes, array_map($expression, $colonnes)));
            }

            DB::table('lignes_vente')
                ->whereIn('vente_id', DB::table('ventes')->where('boutique_id', $boutiqueId)->select('id'))
                ->update(['prix_unitaire' => $expression('prix_unitaire'), 'total_ligne' => $expression('total_ligne')]);
        });
    }

    /** @param  int  $puissance  décimales gagnées (positif) ou perdues (négatif) */
    public static function appliquer(string $boutiqueId, int $puissance): void
    {
        $expression = fn (string $col) => DB::raw($puissance > 0
            ? "{$col} * ".(10 ** $puissance)
            : "ROUND({$col} / ".(10 ** -$puissance).')');

        DB::transaction(function () use ($boutiqueId, $expression): void {
            foreach (self::COLONNES as $table => $colonnes) {
                DB::table($table)->where('boutique_id', $boutiqueId)
                    ->update(array_combine($colonnes, array_map($expression, $colonnes)));
            }

            DB::table('lignes_vente')
                ->whereIn('vente_id', DB::table('ventes')->where('boutique_id', $boutiqueId)->select('id'))
                ->update(['prix_unitaire' => $expression('prix_unitaire'), 'total_ligne' => $expression('total_ligne')]);
        });
    }
}
