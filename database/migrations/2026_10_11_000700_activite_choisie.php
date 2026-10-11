<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le propriétaire choisit son activité (commerce, grossiste, pharmacie,
 * restaurant, pressing) à sa première ouverture. Les boutiques installées
 * (plus d'une semaine, ou déjà pharmacie, restaurant ou pressing) ont déjà
 * choisi ; les inscrits de la semaine se la verront demander une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->timestamp('activite_choisie_le')->nullable()->after('activite');
        });
        DB::table('boutiques')
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays(7))->orWhere('activite', '!=', 'commerce'))
            ->update(['activite_choisie_le' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn('activite_choisie_le');
        });
    }
};
