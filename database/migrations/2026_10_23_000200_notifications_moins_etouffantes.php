<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moins de notifications, mieux ciblées (audit du 23/10/2026 : 13 362 envois
 * en 7 jours, lus à 1 %) :
 * - une vidéo d'aide n'est plus annoncée à sa publication : elle attend le
 *   résumé du lundi (une annonce pour toutes les vidéos de la semaine) ;
 * - une annonce reportée (le compte en a déjà reçu une dans la journée)
 *   reprend le lendemain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutoriels', function (Blueprint $table): void {
            $table->boolean('a_annoncer')->default(false)->after('actif');
            $table->timestamp('annonce_le')->nullable()->after('a_annoncer');
        });
        // Les vidéos déjà en ligne ont été annoncées : le premier résumé ne les reprend pas.
        DB::table('tutoriels')->update(['annonce_le' => now()]);

        Schema::table('annonces', function (Blueprint $table): void {
            $table->timestamp('reprise_le')->nullable()->after('derniere_diffusion');
        });
    }

    public function down(): void
    {
        Schema::table('tutoriels', function (Blueprint $table): void {
            $table->dropColumn(['a_annoncer', 'annonce_le']);
        });
        Schema::table('annonces', function (Blueprint $table): void {
            $table->dropColumn('reprise_le');
        });
    }
};
