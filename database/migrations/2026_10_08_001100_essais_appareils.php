<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Téléphones déjà utilisés par un propriétaire de boutique, par leur empreinte
 * (identifiant de l'appareil haché par l'application, jamais en clair).
 *
 * Une inscription depuis un téléphone déjà connu n'obtient pas de nouvel essai
 * gratuit : se réinscrire avec un autre numéro (ou un chiffre en moins) ne
 * redonne plus 7 jours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('essais_appareils', function (Blueprint $table) {
            $table->id();
            $table->char('empreinte', 64)->index();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['empreinte', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('essais_appareils');
    }
};
