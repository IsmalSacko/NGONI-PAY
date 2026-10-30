<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprimer son compte puis se réinscrire redonnait un essai gratuit : le
 * téléphone noté partait avec le compte (cascade), et le numéro était libéré.
 * Le téléphone reste noté (sans compte), et le numéro d'un propriétaire
 * supprimé est gardé, haché : une réinscription naît avec un essai échu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('essais_appareils', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('essais_appareils', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('numeros_essai_utilises', function (Blueprint $table): void {
            // sha256 du numéro au format international : jamais le numéro en clair.
            $table->char('numero', 64)->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros_essai_utilises');
        Schema::table('essais_appareils', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('essais_appareils', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
