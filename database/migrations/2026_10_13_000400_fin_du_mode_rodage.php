<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Fin du mode rodage : la réinitialisation (Ma boutique) remet une boutique à
 * zéro quand il faut. Toutes les boutiques vendent en mode réel ; les ventes
 * d'essai déjà faites restent dans l'historique, marquées ESSAI. La colonne
 * reste, à faux, pour les applications qui la lisent encore.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('boutiques')->where('mode_rodage', true)->update(['mode_rodage' => false]);
    }

    public function down(): void {}
};
