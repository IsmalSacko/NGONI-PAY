<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unités créées par la boutique (« tas », « boule »…) quand la liste de base
 * ne suffit pas : leur nom sert de code sur l'article et sur la ligne de
 * vente. Les colonnes d'unité s'élargissent en conséquence (« suppositoire »
 * dépassait déjà 10 caractères).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unites', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 20);
            // Pluriel irrégulier ; vide : « botte » → « bottes », « tas » reste « tas ».
            $t->string('pluriel', 24)->nullable();
            $t->timestamps();
            $t->unique(['boutique_id', 'nom']);
        });

        Schema::table('produits', fn (Blueprint $t) => $t->string('unite', 40)->nullable()->change());
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->string('unite', 40)->nullable()->change());
        Schema::table('lignes_achat', fn (Blueprint $t) => $t->string('unite', 40)->nullable()->change());
    }

    public function down(): void
    {
        Schema::dropIfExists('unites');
    }
};
