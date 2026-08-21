<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durée souscrite, portée par la demande.
 *
 * Le nombre de mois y figurait déjà, mais pas la durée choisie : « 3 » ne dit
 * pas si le commerçant a pris un trimestre ou trois mois à l'unité, et
 * l'exploitant doit relire le tarif pour l'écrire sur sa facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->string('cycle', 20)->default('monthly')->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropColumn('cycle');
        });
    }
};
