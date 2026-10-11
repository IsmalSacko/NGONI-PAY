<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaire guidé : on compte article par article (à plusieurs, en
 * continuant de vendre), puis on valide ; chaque écart devient un ajustement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            // en_cours → valide, ou abandonne.
            $table->string('statut', 12)->default('en_cours');
            $table->uuid('cree_par');
            $table->uuid('valide_par')->nullable();
            $table->timestamp('valide_le')->nullable();
            // Figés à la validation : ce qu'il manquait, ce qu'il y avait en trop (au prix d'achat).
            $table->integer('valeur_manquante')->default(0);
            $table->integer('valeur_surplus')->default(0);
            $table->unsignedInteger('articles_corriges')->default(0);
            $table->timestamps();
            $table->index(['boutique_id', 'statut']);
        });

        Schema::create('lignes_inventaire', function (Blueprint $table) {
            $table->id();
            $table->uuid('inventaire_id');
            $table->uuid('produit_id');
            $table->decimal('quantite_comptee', 14, 3);
            // Le stock affiché au moment du comptage : les ventes faites après
            // s'en déduisent à la validation.
            $table->decimal('stock_au_comptage', 14, 3);
            $table->uuid('compte_par');
            $table->timestamps();
            $table->unique(['inventaire_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_inventaire');
        Schema::dropIfExists('inventaires');
    }
};
