<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnement payant : un rappel 3 jours avant la fin (rappel_fin_pour) et un
 * autre la veille (rappel_veille_pour), chacun une fois par échéance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->date('rappel_veille_pour')->nullable()->after('rappel_fin_pour');
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn('rappel_veille_pour');
        });
    }
};
