<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Présence en temps réel : l'application ouverte se signale toutes les 15 s et
 * annonce son départ. `presence_jusqua` dit jusqu'à quand elle est tenue pour
 * ouverte ; vide, l'utilisateur n'a qu'une application d'avant ce signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('presence_jusqua')->nullable()->index()->after('vu_le');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('presence_jusqua');
        });
    }
};
