<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Articles de départ déjà installés : « (exemple) » ajouté au nom, comme pour
 * les boutiques neuves. Seulement ceux dont le commerçant n'a touché ni le nom
 * ni le format — un article qu'il a repris à son compte n'est plus un exemple.
 * Les tickets passés gardent l'ancien nom (copié dans lignes_vente).
 */
return new class extends Migration
{
    private const NOMS = [['Riz', '5 kg'], ['Huile', '1 L']];

    public function up(): void
    {
        foreach (self::NOMS as [$nom, $format]) {
            DB::table('produits')
                ->where('photo', 'like', '%-depart.png')
                ->where('nom', $nom)
                ->where('format', $format)
                ->update(['nom' => "{$nom} (exemple)"]);
        }
    }

    public function down(): void {}
};
