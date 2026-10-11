<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le revendeur commande dans son unité de commande (2 cartons d'huile) ; le
 * stock reste compté dans son unité de base (48 L).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->string('unite_commande', 30)->nullable()->after('unite');
            $table->decimal('contenance_commande', 12, 3)->default(1)->after('unite_commande');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->dropColumn(['unite_commande', 'contenance_commande']);
        });
    }
};
