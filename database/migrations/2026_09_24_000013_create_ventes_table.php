<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une vente encaissée à la caisse tactile. `reference_locale` est l'UUID
 * généré par le client (tablette) au moment de l'encaissement, hors ligne ou
 * non : il permet à la synchronisation d'être idempotente si la requête est
 * rejouée après une coupure réseau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('client_id')->nullable()->index();
            $table->uuid('reference_locale')->nullable();
            $table->unsignedInteger('numero');
            $table->unsignedInteger('sous_total');
            $table->unsignedInteger('remise')->default(0);
            $table->unsignedInteger('tva')->default(0);
            $table->unsignedInteger('total');
            $table->string('moyen_paiement', 20);
            $table->unsignedInteger('montant_recu')->nullable();
            $table->unsignedInteger('monnaie_rendue')->nullable();
            $table->string('statut', 20)->default('validee');
            $table->boolean('vendue_hors_ligne')->default(false);
            $table->timestamp('synchronisee_le')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->unique(['boutique_id', 'numero']);
            $table->unique(['boutique_id', 'reference_locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventes');
    }
};
