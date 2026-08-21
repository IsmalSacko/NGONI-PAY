<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications adressées à un commerçant.
 *
 * Les décisions de l'exploitant — abonnement approuvé, refusé, compte désactivé
 * — ne parvenaient jamais à l'intéressé : il devait rouvrir l'application et
 * deviner. L'application tenait bien une liste de notifications, mais purement
 * locale : elle n'y inscrivait que ce qu'elle faisait elle-même.
 *
 * Nommée `app_notifications` et non `notifications` : cette dernière est la table
 * du système de notifications de Laravel, et les confondre rendrait l'une des
 * deux inutilisable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Le business concerné, quand il y en a un : la notification y renvoie.
            $table->foreignId('business_id')->nullable()
                ->constrained('businesses')->nullOnDelete();

            $table->string('type', 40);
            $table->string('title', 120);
            $table->string('body', 500);
            // Écran à ouvrir depuis la notification.
            $table->string('route')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
