<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Achats et fournisseurs : réception de marchandise (stock et prix d'achat
 * mis à jour), paiements et ce que l'on doit à chaque fournisseur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->string('nom');
            $table->string('telephone', 30)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
        });

        Schema::create('achats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('fournisseur_id')->nullable()->index();
            $table->uuid('user_id');
            $table->string('reference', 60)->nullable();
            $table->unsignedInteger('total');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('fournisseur_id')->references('id')->on('fournisseurs')->nullOnDelete();
        });

        Schema::create('lignes_achat', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('achat_id')->index();
            $table->uuid('produit_id')->nullable();
            $table->string('nom_produit');
            $table->unsignedInteger('quantite');
            $table->unsignedInteger('prix_achat');
            $table->unsignedInteger('total_ligne');
            $table->timestamps();
            $table->foreign('achat_id')->references('id')->on('achats')->cascadeOnDelete();
            $table->foreign('produit_id')->references('id')->on('produits')->nullOnDelete();
        });

        Schema::create('paiements_fournisseur', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('fournisseur_id')->index();
            $table->uuid('user_id');
            $table->unsignedInteger('montant');
            $table->string('moyen_paiement', 30);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('fournisseur_id')->references('id')->on('fournisseurs')->cascadeOnDelete();
        });

        // Nouvelles permissions (achats.view, achats.create) pour admin et gérant.
        Artisan::call('ecaisse:sync-role-permissions');
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_fournisseur');
        Schema::dropIfExists('lignes_achat');
        Schema::dropIfExists('achats');
        Schema::dropIfExists('fournisseurs');
    }
};
