<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('categorie_produit_id')->nullable()->index();
            $table->string('nom');
            // Unité de vente affichée sous le nom : « Sac 5 kg », « Bouteille 1 L ».
            $table->string('format')->nullable();
            // Code court affiché sur la tuile de la caisse tactile (« RZ », « HA »).
            $table->string('code', 4)->nullable();
            $table->string('code_barre')->nullable();
            $table->unsignedInteger('prix_achat')->nullable();
            $table->unsignedInteger('prix_vente');
            $table->decimal('taux_tva', 5, 2)->default(18.00);
            $table->integer('stock')->default(0);
            $table->unsignedInteger('seuil_alerte')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('categorie_produit_id')->references('id')->on('categories_produits')->nullOnDelete();
            $table->unique(['boutique_id', 'code_barre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
