<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Séance de caisse : un caissier déclare un fond initial en ouvrant sa
 * caisse, un fond final en la fermant. L'écart (fond_final observé moins
 * fond attendu = fond_initial + espèces encaissées pendant la séance) est
 * calculé une fois pour toutes à la fermeture et stocké, plutôt que
 * recalculé à la volée — un ticket annulé ou une vente corrigée après coup
 * ne doit pas faire bouger l'écart d'une séance déjà clôturée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_caisse', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedInteger('fond_initial');
            $table->unsignedInteger('fond_final')->nullable();
            $table->integer('ecart')->nullable();
            $table->string('statut', 20)->default('ouverte');
            $table->timestamp('ouverte_le');
            $table->timestamp('fermee_le')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('ventes', function (Blueprint $table) {
            $table->uuid('session_caisse_id')->nullable()->index()->after('user_id');
            $table->foreign('session_caisse_id')->references('id')->on('sessions_caisse')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign(['session_caisse_id']);
            $table->dropColumn('session_caisse_id');
        });

        Schema::dropIfExists('sessions_caisse');
    }
};
