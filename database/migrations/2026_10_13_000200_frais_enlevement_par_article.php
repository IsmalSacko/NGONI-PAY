<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Frais d'enlèvement : un montant TTC par unité vendue, fixé article par
 * article (Export 50 cl : 600 F le casier), ajouté par la caisse sur une ligne
 * « Frais d'enlèvement ». Remplace l'article « frais » (est_frais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t): void {
            $t->dropColumn('est_frais');
            $t->unsignedInteger('frais_enlevement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $t): void {
            $t->dropColumn('frais_enlevement');
            $t->boolean('est_frais')->default(false);
        });
    }
};
