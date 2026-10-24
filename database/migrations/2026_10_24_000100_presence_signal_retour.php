<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retour du signal de présence (retiré le 23/10 faute de nouvelle version de
 * l'application) : la version qui l'envoie arrive. `presence_jusqua` dit
 * jusqu'à quand l'application est tenue pour ouverte ; vide, l'utilisateur
 * n'a qu'une version d'avant ce signal, jugée sur son passage de chaque minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'presence_jusqua')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('presence_jusqua')->nullable()->index()->after('vu_le');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'presence_jusqua')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['presence_jusqua']);
                $table->dropColumn('presence_jusqua');
            });
        }
    }
};
