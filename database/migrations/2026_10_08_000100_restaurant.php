<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Restaurant : la commande (table, à emporter, livraison, téléphone), ses
 * lignes suivies en cuisine, et la vente qui naît à l'addition. Autour : les
 * tables de la salle, les réservations, les options et formules de la carte,
 * les ingrédients et les recettes. N'existe que pour les boutiques en
 * activité restaurant : rien ne change pour les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 40);
            $t->string('zone', 40)->nullable();
            $t->unsignedSmallInteger('places')->nullable();
            $t->unsignedSmallInteger('ordre')->default(0);
            $t->timestamps();
        });

        Schema::create('commandes_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->unsignedInteger('numero');
            $t->uuid('reference_locale')->nullable()->unique();
            // sur_place, emporter, livraison
            $t->string('type', 20)->default('sur_place');
            $t->boolean('telephone')->default(false);
            $t->string('table', 40)->nullable();
            $t->unsignedSmallInteger('couverts')->nullable();
            $t->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->string('adresse', 255)->nullable();
            $t->dateTime('heure_prevue')->nullable();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // ouverte, payee, annulee
            $t->string('statut', 20)->default('ouverte');
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('paye')->default(0);
            $t->unsignedBigInteger('acompte')->default(0);
            $t->string('moyen_acompte', 30)->nullable();
            $t->unsignedBigInteger('pourboire')->default(0);
            $t->unsignedSmallInteger('envois')->default(0);
            $t->dateTime('payee_le')->nullable();
            $t->dateTime('terminee_le')->nullable();
            $t->dateTime('annulee_le')->nullable();
            $t->string('motif_annulation', 255)->nullable();
            $t->text('notes')->nullable();
            $t->json('historique')->nullable();
            $t->timestamps();
            $t->unique(['boutique_id', 'numero']);
            $t->index(['boutique_id', 'statut']);
        });

        Schema::create('lignes_commande_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('commande_id')->constrained('commandes_restaurant')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->string('nom', 160);
            $t->unsignedInteger('quantite');
            $t->unsignedBigInteger('prix_unitaire');
            $t->unsignedBigInteger('total_ligne');
            // Options choisies [{nom, prix}] ; composition d'une formule [nom…].
            $t->json('options')->nullable();
            $t->json('composition')->nullable();
            $t->string('note', 160)->nullable();
            // attente (pas encore envoyée), en_cuisine, prete, servie, annulee
            $t->string('etat', 20)->default('attente');
            $t->unsignedSmallInteger('envoi')->nullable();
            $t->dateTime('envoyee_le')->nullable();
            $t->dateTime('prete_le')->nullable();
            $t->dateTime('servie_le')->nullable();
            // Payée par cette vente (addition partagée : plusieurs ventes par commande).
            $t->foreignUuid('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->unsignedSmallInteger('ordre')->default(0);
        });

        Schema::create('encaissements_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('commande_id')->constrained('commandes_restaurant')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('session_caisse_id')->nullable()->constrained('sessions_caisse')->nullOnDelete();
            // acompte, remboursement, pourboire
            $t->string('type', 20);
            $t->unsignedBigInteger('montant');
            $t->string('moyen_paiement', 30);
            $t->timestamps();
            $t->index(['boutique_id', 'created_at']);
        });

        Schema::table('ventes', function (Blueprint $t): void {
            $t->foreignUuid('commande_restaurant_id')->nullable()->constrained('commandes_restaurant')->nullOnDelete();
        });

        Schema::create('reservations_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->string('nom', 120);
            $t->string('telephone', 40)->nullable();
            $t->dateTime('le');
            $t->unsignedSmallInteger('couverts')->default(2);
            $t->string('table', 40)->nullable();
            $t->string('note', 255)->nullable();
            // prevue, arrivee, annulee
            $t->string('statut', 20)->default('prevue');
            $t->foreignUuid('commande_id')->nullable()->constrained('commandes_restaurant')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'le']);
        });

        Schema::create('options_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->constrained('produits')->cascadeOnDelete();
            // « Cuisson », « Accompagnement », « Supplément »…
            $t->string('groupe', 40)->nullable();
            $t->string('nom', 60);
            $t->unsignedBigInteger('prix')->default(0);
            $t->unsignedSmallInteger('ordre')->default(0);
            $t->timestamps();
        });

        Schema::create('formules_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->unique()->constrained('produits')->cascadeOnDelete();
            // [{titre: « Entrée », produits: [id…]}, …]
            $t->json('etapes');
            $t->timestamps();
        });

        Schema::create('ingredients_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 80);
            $t->string('unite', 20)->default('kg');
            $t->decimal('quantite', 12, 3)->default(0);
            $t->decimal('seuil', 12, 3)->nullable();
            // Coût d'une unité (le dernier prix d'achat), pour le coût de revient des plats.
            $t->unsignedBigInteger('cout_unitaire')->default(0);
            $t->timestamps();
        });

        Schema::create('recettes_restaurant', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->constrained('produits')->cascadeOnDelete();
            $t->foreignUuid('ingredient_id')->constrained('ingredients_restaurant')->cascadeOnDelete();
            $t->decimal('quantite', 12, 3);
            $t->timestamps();
            $t->unique(['produit_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recettes_restaurant');
        Schema::dropIfExists('ingredients_restaurant');
        Schema::dropIfExists('formules_restaurant');
        Schema::dropIfExists('options_restaurant');
        Schema::dropIfExists('reservations_restaurant');
        Schema::table('ventes', fn (Blueprint $t) => $t->dropConstrainedForeignId('commande_restaurant_id'));
        Schema::dropIfExists('encaissements_restaurant');
        Schema::dropIfExists('lignes_commande_restaurant');
        Schema::dropIfExists('commandes_restaurant');
        Schema::dropIfExists('tables_restaurant');
    }
};
