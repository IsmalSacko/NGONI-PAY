<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un paiement en ligne (PayPal, PayDunya) émis depuis la caisse. Le panier est
 * figé ici (`lignes`, `remise`, `client_id`) : c'est le serveur qui crée la
 * vente une fois le paiement confirmé, sans dépendre de la tablette — si elle
 * plante ou se déconnecte après que le client a payé, la vente n'est pas perdue.
 * `vente_id` est unique : un paiement ne produit jamais deux ventes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('client_id')->nullable();
            // UUID généré par la tablette à chaque tentative : rejouer la même
            // requête (coupure réseau) ne crée pas un second paiement.
            $table->uuid('reference_locale')->nullable();
            $table->string('fournisseur', 20);
            $table->string('moyen_paiement', 20);
            $table->string('statut', 20)->default('en_attente')->index();
            // Total de la vente en unité de la boutique (FCFA), calculé par le serveur.
            $table->unsignedInteger('montant');
            $table->unsignedInteger('remise')->default(0);
            // Ce qui est réellement facturé par le fournisseur (ex. EUR pour PayPal).
            $table->string('devise_fournisseur', 3);
            $table->string('montant_fournisseur', 20);
            // Jeton de facture PayDunya / identifiant de commande PayPal.
            $table->string('reference_fournisseur')->nullable();
            $table->text('url_paiement')->nullable();
            $table->json('lignes');
            $table->uuid('vente_id')->nullable()->unique();
            $table->text('erreur')->nullable();
            $table->timestamp('confirme_le')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('vente_id')->references('id')->on('ventes')->nullOnDelete();
            $table->unique(['fournisseur', 'reference_fournisseur']);
            $table->unique(['boutique_id', 'reference_locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
