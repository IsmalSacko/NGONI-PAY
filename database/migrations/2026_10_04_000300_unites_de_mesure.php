<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vente au poids, à la mesure et au demi : chaque article dit à quoi il se
 * vend (vide = à la pièce), et les quantités prennent jusqu'à trois décimales
 * — 1,250 kg, un demi-pain. Les nombres entiers d'avant restent tels quels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t): void {
            $t->string('unite', 10)->nullable()->after('format');
            $t->decimal('stock', 14, 3)->default(0)->change();
            $t->decimal('seuil_alerte', 14, 3)->default(0)->change();
        });

        // L'unité est figée sur la ligne : le ticket réimprimé dit « 1,25 kg ».
        Schema::table('lignes_vente', function (Blueprint $t): void {
            $t->decimal('quantite', 14, 3)->change();
            $t->string('unite', 10)->nullable()->after('quantite');
        });

        Schema::table('mouvements_stock', function (Blueprint $t): void {
            $t->decimal('quantite', 14, 3)->change();
            $t->decimal('stock_apres', 14, 3)->change();
        });

        Schema::table('lignes_achat', function (Blueprint $t): void {
            $t->decimal('quantite', 14, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $t): void {
            $t->dropColumn('unite');
            $t->integer('stock')->default(0)->change();
            $t->unsignedInteger('seuil_alerte')->default(0)->change();
        });
        Schema::table('lignes_vente', function (Blueprint $t): void {
            $t->dropColumn('unite');
            $t->unsignedInteger('quantite')->change();
        });
        Schema::table('mouvements_stock', function (Blueprint $t): void {
            $t->integer('quantite')->change();
            $t->integer('stock_apres')->change();
        });
        Schema::table('lignes_achat', fn (Blueprint $t) => $t->unsignedInteger('quantite')->change());
    }
};
