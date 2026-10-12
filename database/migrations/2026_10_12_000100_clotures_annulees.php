<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Journée clôturée par erreur, rouverte le jour même : son ticket Z quitte
 * `clotures` (une seule clôture par jour) et sa trace est gardée ici — numéro,
 * chiffres figés, qui l'avait clôturée et qui l'a rouverte. Le numéro n'est
 * jamais réutilisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_annulees', function (Blueprint $t): void {
            $t->id();
            $t->uuid('boutique_id')->index();
            $t->unsignedInteger('numero');
            $t->date('jour_affaire');
            $t->uuid('cloturee_par')->nullable();
            $t->timestamp('cloturee_le')->nullable();
            $t->json('totaux')->nullable();
            $t->uuid('rouverte_par')->nullable();
            $t->string('rouverte_par_nom', 255);
            $t->timestamp('rouverte_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_annulees');
    }
};
