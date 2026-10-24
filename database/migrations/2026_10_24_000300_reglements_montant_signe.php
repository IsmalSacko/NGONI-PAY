<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Rendre » enregistre un règlement négatif (l'argent rendu au client) : la
 * colonne, créée sans signe, le refusait sur MySQL (« Out of range »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglements_credit', fn (Blueprint $t) => $t->bigInteger('montant')->change());
    }

    public function down(): void
    {
        Schema::table('reglements_credit', fn (Blueprint $t) => $t->unsignedInteger('montant')->change());
    }
};
