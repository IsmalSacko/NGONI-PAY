<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annonces de l'exploitant : mise à jour de l'application, message libre ou
 * campagne programmée (éventuellement répétée). Chaque envoi dépose une
 * notification dans l'application de chaque destinataire, et un e-mail si
 * demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annonces', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);            // mise_a_jour | message | campagne
            $table->string('titre', 120);
            $table->text('message');
            $table->string('version', 20)->nullable();
            $table->string('lien')->nullable();
            $table->string('audience', 20)->default('tous'); // tous | essai | basic | pro | expires | selection
            $table->boolean('par_email')->default(false);
            $table->string('statut', 20)->default('brouillon'); // brouillon | programmee | envoyee
            $table->timestamp('programmee_le')->nullable();
            $table->string('recurrence', 20)->nullable();      // hebdomadaire | mensuelle
            $table->timestamp('derniere_diffusion')->nullable();
            $table->unsignedInteger('nb_notifies')->default(0);
            $table->unsignedInteger('nb_emails')->default(0);
            $table->unsignedInteger('nb_echecs')->default(0);
            $table->foreignUuid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['statut', 'programmee_le']);
        });

        Schema::create('annonce_cibles', function (Blueprint $table) {
            $table->foreignId('annonce_id')->constrained('annonces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['annonce_id', 'user_id']);
        });

        Schema::create('notifications_app', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('annonce_id')->nullable()->constrained('annonces')->nullOnDelete();
            $table->string('type', 20);
            $table->string('titre', 120);
            $table->text('message');
            $table->string('lien')->nullable();
            $table->timestamp('lue_le')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'lue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_app');
        Schema::dropIfExists('annonce_cibles');
        Schema::dropIfExists('annonces');
    }
};
