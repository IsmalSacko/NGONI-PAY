<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte créé par le robot de test de Google Play (rapport de pré-lancement) :
 * ni alerte, ni vrai commerçant ; effacé au bout d'un jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('compte_test')->default(false)->after('conditions_version'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('compte_test'));
    }
};
