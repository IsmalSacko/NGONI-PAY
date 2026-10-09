<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aide et tutoriels : les vidéos YouTube de l'exploitant, listées dans
 * l'application (Plus → Aide et tutoriels) et gérées depuis la console, sans
 * republier l'application pour en ajouter une.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutoriels', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 120);
            $table->string('sous_titre', 160)->nullable();
            $table->string('categorie', 20)->default('ventes');
            $table->string('url', 255);
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->index(['actif', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutoriels');
    }
};
