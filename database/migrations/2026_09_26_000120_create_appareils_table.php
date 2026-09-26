<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Appareils des comptes, pour les notifications push (jeton Firebase). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('jeton', 512)->unique();
            $table->string('plateforme', 20)->default('android');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils');
    }
};
