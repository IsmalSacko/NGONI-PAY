<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un pressing ne voit que ses tarifs (Produit::pourActivite : tarifs non nuls).
 * Ses habits enregistrés sans aucun prix deviendraient invisibles : ils
 * reçoivent une liste de prix vide, et restent dans Tarifs pour être chiffrés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('produits')
            ->whereNull('tarifs')
            ->whereIn('boutique_id', DB::table('boutiques')->where('activite', 'pressing')->select('id'))
            ->update(['tarifs' => '[]']);
    }

    public function down(): void
    {
        // Rien à défaire : une liste vide et l'absence de prix disent la même chose.
    }
};
