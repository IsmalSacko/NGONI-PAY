<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes aux grossistes : un commerçant (revendeur) commande à son
 * fournisseur ; si celui-ci tient aussi une boutique Ngoni, la commande arrive
 * dans ses « Commandes reçues ». À la livraison, une vente en gros naît chez le
 * grossiste et un achat chez le revendeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le fournisseur relié à la boutique Ngoni du grossiste (même téléphone).
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->uuid('boutique_liee_id')->nullable()->after('telephone')->index();
        });

        Schema::create('commandes_fournisseur', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('fournisseur_id')->index();
            $table->uuid('boutique_grossiste_id')->nullable()->index();
            $table->string('numero', 12);
            // envoyee → confirmee → livree ; ou refusee / annulee.
            $table->string('statut', 20)->default('envoyee');
            $table->string('note', 255)->nullable();
            $table->string('motif', 255)->nullable();
            $table->uuid('cree_par')->index();
            $table->unsignedBigInteger('total_estime')->default(0);
            $table->uuid('vente_id')->nullable()->index();
            $table->uuid('achat_id')->nullable()->index();
            $table->timestamp('confirmee_le')->nullable();
            $table->timestamp('livree_le')->nullable();
            $table->timestamps();
            $table->index(['boutique_id', 'statut']);
            $table->index(['boutique_grossiste_id', 'statut']);
        });

        Schema::create('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->uuid('commande_id')->index();
            $table->uuid('produit_id')->index();
            $table->string('nom', 160);
            $table->decimal('quantite', 12, 3);
            $table->unsignedBigInteger('prix_estime')->default(0);
            // Chez le grossiste : son article, la quantité qu'il livre, son prix.
            $table->uuid('produit_grossiste_id')->nullable()->index();
            $table->decimal('quantite_confirmee', 12, 3)->nullable();
            $table->unsignedBigInteger('prix_grossiste')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_commande_fournisseur');
        Schema::dropIfExists('commandes_fournisseur');
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropIndex(['boutique_liee_id']);
            $table->dropColumn('boutique_liee_id');
        });
    }
};
