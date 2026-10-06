<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pressing : chaque habit a un prix par service (lavage + repassage,
 * repassage seul…), et l'express se paie plus cher — par une majoration de
 * la boutique, ou par un prix express écrit sur l'habit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 40);
            $t->unsignedSmallInteger('ordre')->default(0);
            $t->timestamps();
            $t->unique(['boutique_id', 'nom']);
        });

        // [{service_id, prix, prix_express}] : prix_express vide → la majoration de la boutique.
        Schema::table('produits', fn (Blueprint $t) => $t->json('tarifs')->nullable()->after('paliers'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->unsignedSmallInteger('express_majoration_pct')->default(50));
        Schema::table('ventes', fn (Blueprint $t) => $t->boolean('express')->default(false));
        Schema::table('lignes_vente', function (Blueprint $t): void {
            $t->string('service', 40)->nullable();
            $t->boolean('express')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn(['service', 'express']));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('express'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('express_majoration_pct'));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn('tarifs'));
        Schema::dropIfExists('services_pressing');
    }
};
