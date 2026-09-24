<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligne d'une vente. Le nom, le prix et le taux de TVA du produit sont
 * dupliqués ici au moment de la vente : le prix catalogue change avec le
 * temps, mais un ticket déjà émis doit rester lisible tel qu'il a été payé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_vente', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vente_id')->index();
            $table->uuid('produit_id')->nullable()->index();
            $table->string('nom_produit');
            $table->unsignedInteger('prix_unitaire');
            $table->decimal('taux_tva', 5, 2)->default(18.00);
            $table->unsignedInteger('quantite');
            $table->unsignedInteger('total_ligne');
            $table->timestamps();

            $table->foreign('vente_id')->references('id')->on('ventes')->cascadeOnDelete();
            $table->foreign('produit_id')->references('id')->on('produits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_vente');
    }
};
