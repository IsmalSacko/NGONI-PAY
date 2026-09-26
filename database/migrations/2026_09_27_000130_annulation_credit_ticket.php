<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulation tracée des ventes, suivi des crédits clients (règlements), et
 * mentions du ticket propres à la boutique (NIF, RCCM, message).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->timestamp('annulee_le')->nullable();
            $table->uuid('annulee_par')->nullable();
            $table->string('motif_annulation')->nullable();
        });

        Schema::create('reglements_credit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('client_id')->index();
            $table->uuid('user_id');
            $table->unsignedInteger('montant');
            $table->string('moyen_paiement', 30);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('identifiant_fiscal', 60)->nullable();
            $table->string('rccm', 60)->nullable();
            $table->string('message_ticket', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['identifiant_fiscal', 'rccm', 'message_ticket']));
        Schema::dropIfExists('reglements_credit');
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn(['annulee_le', 'annulee_par', 'motif_annulation']));
    }
};
