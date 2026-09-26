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

    public static function entreDevises(string $boutiqueId, string $ancienne, string $nouvelle): void
    {
        $ecart = Currencies::decimals($nouvelle) - Currencies::decimals($ancienne);
        if ($ecart !== 0) {
            self::appliquer($boutiqueId, $ecart);
        }
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
