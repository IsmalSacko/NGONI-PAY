<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque rappel de paiement envoyé à un commerçant qui doit encore une part
 * de son abonnement (crédit dans la boutique des ventes d'abonnement) : la
 * console affiche la dernière relance, la tâche du mois ne relance pas deux
 * fois de suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relances_creances', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id')->nullable()->index();
            $table->unsignedInteger('montant');
            $table->foreignId('annonce_id')->nullable()->constrained('annonces')->nullOnDelete();
            $table->boolean('automatique')->default(false);
            $table->string('par', 120)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relances_creances');
    }
};
