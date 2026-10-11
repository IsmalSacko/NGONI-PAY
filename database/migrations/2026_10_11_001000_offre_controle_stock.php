<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le contrôle du stock (inventaire, pertes) entre dans le Pro ; les
 * descriptions des offres disent enfin ce que chacune contient vraiment
 * (le Basic garde ses séances de caisse).
 */
return new class extends Migration
{
    private const BASIC = 'Jusqu’à 2 boutiques et 5 membres : caisse, tickets et reçus, catalogue et stocks, clients, vente à crédit, séances de caisse, achats et commandes aux grossistes, back-office web.';

    private const PRO = 'Tout le Basic, plus : jusqu’à 5 boutiques, équipe illimitée, contrôle du stock (inventaire, pertes), statistiques avancées, vente en gros, fidélité, dépenses, factures A4 et exports, droits par membre, mode libre, restaurant avancé.';

    public function up(): void
    {
        $pro = DB::table('plans')->where('code', 'pro')->first();
        if ($pro !== null) {
            $fonctions = json_decode((string) $pro->fonctionnalites, true) ?: [];
            if (! in_array('controle_stock', $fonctions, true)) {
                $fonctions[] = 'controle_stock';
            }
            DB::table('plans')->where('code', 'pro')->update(['fonctionnalites' => json_encode(array_values($fonctions)), 'description' => self::PRO]);
        }
        DB::table('plans')->where('code', 'basic')->update(['description' => self::BASIC]);
    }

    public function down(): void
    {
        $pro = DB::table('plans')->where('code', 'pro')->first();
        if ($pro !== null) {
            $fonctions = array_values(array_diff(json_decode((string) $pro->fonctionnalites, true) ?: [], ['controle_stock']));
            DB::table('plans')->where('code', 'pro')->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
