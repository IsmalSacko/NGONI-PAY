<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le revendeur paie un fournisseur qui a Ngoni : le paiement est déclaré au
 * grossiste, qui le confirme (remboursement du crédit client) ou le refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements_fournisseur', function (Blueprint $table) {
            $table->uuid('boutique_grossiste_id')->nullable()->index()->after('note');
            $table->uuid('client_grossiste_id')->nullable()->after('boutique_grossiste_id');
            // a_confirmer → confirme ou refuse ; null : fournisseur sans Ngoni.
            $table->string('statut_grossiste', 15)->nullable()->after('client_grossiste_id');
            $table->uuid('reglement_credit_id')->nullable()->after('statut_grossiste');
        });
    }

    public function down(): void
    {
        Schema::table('paiements_fournisseur', function (Blueprint $table) {
            $table->dropIndex(['boutique_grossiste_id']);
            $table->dropColumn(['boutique_grossiste_id', 'client_grossiste_id', 'statut_grossiste', 'reglement_credit_id']);
        });
    }
};
