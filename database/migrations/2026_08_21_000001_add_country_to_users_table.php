<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pays du compte : ce qui donne l'indicatif du numéro de connexion.
 *
 * Les comptes existants ont tous été créés quand l'application ne desservait
 * que le Mali, et leur numéro porte déjà « +223 » : le défaut les décrit
 * fidèlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country', 2)->default('ML')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
