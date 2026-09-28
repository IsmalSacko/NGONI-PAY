<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière activité de chaque utilisateur : quand, depuis quel appareil, dans
 * quelle boutique. Tenue par le middleware NoterPresence, lue par la console.
 *
 * Reprise de l'existant : la dernière utilisation d'un jeton donne une
 * première date, et un appareil inscrit aux notifications, sa plateforme —
 * la console n'attend pas la prochaine connexion de chacun pour se remplir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('vu_le')->nullable()->index();
            $table->string('vu_plateforme', 20)->nullable();
            $table->string('vu_modele', 80)->nullable();
            $table->string('vu_version', 20)->nullable();
            $table->uuid('vu_boutique_id')->nullable();
            $table->foreign('vu_boutique_id')->references('id')->on('boutiques')->nullOnDelete();
        });

        DB::table('users')->update([
            'vu_le' => DB::table('personal_access_tokens')
                ->selectRaw('MAX(last_used_at)')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereColumn('tokenable_id', 'users.id'),
        ]);
        DB::table('users')
            ->whereExists(fn ($q) => $q->from('appareils')->whereColumn('appareils.user_id', 'users.id'))
            ->update(['vu_plateforme' => 'android']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['vu_boutique_id']);
            $table->dropColumn(['vu_le', 'vu_plateforme', 'vu_modele', 'vu_version', 'vu_boutique_id']);
        });
    }
};
