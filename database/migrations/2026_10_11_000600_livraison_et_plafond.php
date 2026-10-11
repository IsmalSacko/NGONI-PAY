<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commande « en livraison » ; plafond de crédit d'un client. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table) {
            $table->timestamp('expediee_le')->nullable()->after('confirmee_le');
        });
        Schema::table('clients', function (Blueprint $table) {
            // Ce qu'il peut devoir au plus ; null : pas de limite.
            $table->unsignedInteger('plafond_credit')->nullable()->after('revendeur');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table) {
            $table->dropColumn('expediee_le');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('plafond_credit');
        });
    }
};
