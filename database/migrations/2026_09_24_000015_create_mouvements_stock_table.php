<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des mouvements de stock — entrée (réassort), sortie (vente,
 * casse/péremption) ou ajustement (inventaire). Purement déclaratif : le
 * stock courant reste sur `produits.stock`, ce journal sert à l'audit et à
 * l'historique de l'écran Stocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('produit_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('vente_id')->nullable()->index();
            $table->string('type', 20);
            $table->integer('quantite');
            $table->integer('stock_apres');
            $table->string('motif')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('produit_id')->references('id')->on('produits')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('vente_id')->references('id')->on('ventes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
