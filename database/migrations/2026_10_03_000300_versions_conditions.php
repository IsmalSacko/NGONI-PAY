<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texte exact de chaque version des conditions et de la politique de
 * confidentialité, gardé à la première acceptation : la preuve d'une
 * acceptation renvoie au texte accepté, pas à celui d'aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versions_conditions', function (Blueprint $table) {
            $table->string('version', 20)->primary();
            $table->longText('conditions');
            $table->longText('confidentialite');
            $table->timestamp('archivee_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions_conditions');
    }
};
