<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boutique : racine du multi-tenant. Chaque commerçant qui s'inscrit obtient
 * sa propre boutique — c'est elle, et non l'utilisateur, que pointe le
 * `boutique_id` de toutes les tables métier (voir BelongsToBoutique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boutiques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->string('pays', 2)->default('ML');
            $table->string('devise', 3)->default('XOF');
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutiques');
    }
};
