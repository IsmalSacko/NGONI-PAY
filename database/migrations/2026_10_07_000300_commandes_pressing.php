<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pressing : la commande (le dépôt). Créée au dépôt, clôturée au retrait, où
 * la vraie vente naît. N'existe que pour les boutiques en activité pressing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->unsignedInteger('numero');
            $t->uuid('reference_locale')->nullable()->unique();
            $t->foreignUuid('client_id')->constrained('clients');
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('statut', 20)->default('deposee');
            $t->boolean('express')->default(false);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('acompte')->default(0);
            $t->string('moyen_acompte', 30)->nullable();
            $t->dateTime('retrait_prevu_le')->nullable();
            $t->dateTime('prete_le')->nullable();
            $t->dateTime('retiree_le')->nullable();
            $t->foreignUuid('retiree_par')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->dateTime('annulee_le')->nullable();
            $t->string('motif_annulation', 255)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['boutique_id', 'numero']);
            $t->index(['boutique_id', 'statut']);
        });

        Schema::create('lignes_commande_pressing', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('commande_id')->constrained('commandes_pressing')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->uuid('service_id')->nullable();
            $t->string('nom', 255);
            $t->string('service', 40);
            $t->unsignedInteger('quantite');
            $t->unsignedBigInteger('prix_unitaire');
            $t->unsignedBigInteger('total_ligne');
            $t->string('defauts', 255)->nullable();
        });

        Schema::table('boutiques', fn (Blueprint $t) => $t->unsignedInteger('dernier_numero_commande')->default(0));
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('dernier_numero_commande'));
        Schema::dropIfExists('lignes_commande_pressing');
        Schema::dropIfExists('commandes_pressing');
    }
};
