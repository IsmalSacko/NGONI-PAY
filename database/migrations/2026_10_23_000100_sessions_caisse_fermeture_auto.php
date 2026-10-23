<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse oubliée ouverte : rappel à 23 h (heure du pays), puis fermeture
 * automatique à 4 h, sans comptage (ni fond compté ni écart inventé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            // Fermée par Ngoni, faute de l'avoir été au comptoir : « non comptée ».
            $table->boolean('fermee_auto')->default(false)->after('fermee_le');
            // Le rappel de 23 h n'est envoyé qu'une fois par soir.
            $table->timestamp('rappel_le')->nullable()->after('fermee_auto');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->dropColumn(['fermee_auto', 'rappel_le']);
        });
    }
};
