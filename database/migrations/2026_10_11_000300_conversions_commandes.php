<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le revendeur commande dans ses unités (sachet, litre) ; le grossiste livre
 * dans les siennes (carton, bidon). « 1 carton = 24 sachets » se dit une fois
 * et sert aux commandes suivantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->string('unite', 30)->nullable()->after('nom');
            // Unités du revendeur dans une unité du grossiste.
            $table->decimal('contenance', 12, 3)->default(1)->after('produit_grossiste_id');
        });

        Schema::create('correspondances_produits', function (Blueprint $table) {
            $table->id();
            $table->uuid('boutique_grossiste_id')->index();
            $table->uuid('produit_grossiste_id');
            $table->uuid('produit_revendeur_id');
            $table->decimal('contenance', 12, 3)->default(1);
            $table->timestamps();
            $table->unique(['produit_revendeur_id', 'boutique_grossiste_id'], 'correspondance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondances_produits');
        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->dropColumn(['unite', 'contenance']);
        });
    }
};
