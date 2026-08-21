<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devise du business : celle de tous ses paiements et de ses factures.
 *
 * Portée par le business et non par le compte : un même propriétaire peut tenir
 * une boutique à Bamako et une autre à Conakry, qui ne comptent pas dans la
 * même monnaie.
 *
 * Les paiements déjà enregistrés portent « XOF », valeur qu'écrivait l'API
 * avant ce choix : le défaut garde les montants existants cohérents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('currency', 3)->default('XOF')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
