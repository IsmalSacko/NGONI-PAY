<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les comptes de l'exploitant et ses essais (boutiques à lui, simulations) :
 * hors des chiffres d'abonnements de la Console Ngoni. Marqués depuis elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('compte_interne')->default(false)->after('compte_test'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('compte_interne'));
    }
};
