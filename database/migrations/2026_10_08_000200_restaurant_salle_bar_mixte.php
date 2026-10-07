<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Restaurant : chaque plat part vers son poste (cuisine ou bar, choisi par
 * catégorie) ; options à choix unique ou obligatoire (portion, cuisson…) ;
 * paiement mixte d'une addition (espèces + Orange Money…), noté sur la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande_restaurant', function (Blueprint $t): void {
            $t->string('poste', 10)->default('cuisine')->after('note');
        });

        Schema::table('options_restaurant', function (Blueprint $t): void {
            $t->boolean('choix_unique')->default(false)->after('prix');
            $t->boolean('obligatoire')->default(false)->after('choix_unique');
        });

        Schema::create('postes_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('categorie_produit_id')->unique()->constrained('categories_produits')->cascadeOnDelete();
            // cuisine, bar
            $t->string('poste', 10);
            $t->timestamps();
        });

        Schema::table('ventes', function (Blueprint $t): void {
            // Paiement mixte : [{moyen, montant}], la somme de ce qui est reçu à cette vente.
            $t->json('paiements')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('paiements'));
        Schema::dropIfExists('postes_restaurant');
        Schema::table('options_restaurant', fn (Blueprint $t) => $t->dropColumn(['choix_unique', 'obligatoire']));
        Schema::table('lignes_commande_restaurant', fn (Blueprint $t) => $t->dropColumn('poste'));
    }
};
