<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Le revendeur voit quand le grossiste a ouvert sa commande. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table) {
            $table->timestamp('vue_le')->nullable()->after('motif');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table) {
            $table->dropColumn('vue_le');
        });
    }
};
