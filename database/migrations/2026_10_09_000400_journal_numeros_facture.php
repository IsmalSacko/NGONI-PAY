<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Numéro de facture modifié à la main (mode libre, ou vente d'essai) : chaque
 * changement est gardé — ancien numéro, nouveau, qui, quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_numeros_facture', function (Blueprint $t): void {
            $t->id();
            $t->uuid('boutique_id')->index();
            $t->uuid('user_id')->nullable();
            $t->string('par', 255);
            $t->uuid('vente_id');
            $t->string('ancien', 60);
            $t->string('nouveau', 60);
            $t->timestamp('le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_numeros_facture');
    }
};
