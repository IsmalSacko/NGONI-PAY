<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grille du 28/09/2026.
 *
 * Le Pro n'avait aucune remise sur la durée (30 000 le trimestre, 120 000
 * l'année : trois ou douze fois le mois) ; il suit maintenant l'échelle du
 * Basic — environ -8 % au trimestre, -12 % au semestre, deux mois offerts sur
 * l'année. Les demandes déjà déposées gardent leur montant, figé au dépôt.
 *
 * Les descriptions de l'essai et du Basic annonçaient une boutique (et trois
 * membres pour le Basic) quand les limites réglées sont de deux boutiques (et
 * cinq membres) : le texte dit désormais ce que le plan donne.
 */
return new class extends Migration
{
    private const PRO = ['quarterly' => [30000, 27500], 'biannual' => [60000, 52500], 'yearly' => [120000, 100000]];

    public function up(): void
    {
        $this->tarifs(fn (array $prix): int => $prix[1], fn (array $prix): int => $prix[0]);

        DB::table('plans')->where('code', 'essai')->update([
            'description' => "Offert à l'inscription : toutes les fonctions pendant 7 jours, jusqu'à 2 boutiques et 3 membres, sans engagement.",
        ]);
        DB::table('plans')->where('code', 'basic')->update([
            'description' => 'Jusqu’à 2 boutiques et 5 membres : caisse, montant libre, tickets et reçus, catalogue et stocks, clients, historique et tableau de bord.',
        ]);
    }

    public function down(): void
    {
        $this->tarifs(fn (array $prix): int => $prix[0], fn (array $prix): int => $prix[1]);

        DB::table('plans')->where('code', 'essai')->update([
            'description' => "Offert à l'inscription : toutes les fonctions pendant 7 jours, pour une boutique, sans engagement.",
        ]);
        DB::table('plans')->where('code', 'basic')->update([
            'description' => 'Une boutique et jusqu’à 3 membres : caisse, montant libre, tickets et reçus, catalogue et stocks, clients, historique et tableau de bord.',
        ]);
    }

    /** Ne touche qu'un tarif encore à sa valeur d'origine : un prix changé depuis la console est gardé. */
    private function tarifs(callable $nouveau, callable $ancien): void
    {
        $pro = DB::table('plans')->where('code', 'pro')->value('id');
        if ($pro === null) {
            return;
        }

        foreach (self::PRO as $cycle => $prix) {
            DB::table('plan_tarifs')
                ->where('plan_id', $pro)->where('cycle', $cycle)->where('montant', $ancien($prix))
                ->update(['montant' => $nouveau($prix), 'updated_at' => now()]);
        }
    }
};
