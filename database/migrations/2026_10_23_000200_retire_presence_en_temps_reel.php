<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Présence en temps réel abandonnée : elle demandait une nouvelle version de
 * l'application. La colonne créée par la migration précédente est retirée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'presence_jusqua')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['presence_jusqua']);
                $table->dropColumn('presence_jusqua');
            });
        }
    }

    public function down(): void
    {
        // Rien : la migration précédente sait la recréer.
    }
};
