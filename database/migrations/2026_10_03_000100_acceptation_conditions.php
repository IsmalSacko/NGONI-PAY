<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve d'acceptation des conditions d'utilisation : chaque acceptation est
 * gardée (version, date, adresse IP, appareil, à l'inscription ou à la
 * connexion), jamais effacée ni réécrite. `users.conditions_version` dit
 * seulement la dernière version acceptée, pour savoir vite s'il faut la redemander.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptations_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('version', 20);
            $table->string('source', 20);
            $table->string('ip', 45)->nullable();
            $table->string('appareil', 255)->nullable();
            $table->timestamp('acceptee_le');
            $table->index(['user_id', 'version']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('conditions_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('conditions_version');
        });
        Schema::dropIfExists('acceptations_conditions');
    }
};
