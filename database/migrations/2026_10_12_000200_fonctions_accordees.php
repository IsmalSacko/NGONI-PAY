<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fonctions accordées à la main par l'exploitant (console, « Gérer ») en plus
 * de celles de la formule : codes de Plan::FONCTIONNALITES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonnements', fn (Blueprint $t) => $t->json('fonctions_accordees')->nullable());
    }

    public function down(): void
    {
        Schema::table('abonnements', fn (Blueprint $t) => $t->dropColumn('fonctions_accordees'));
    }
};
