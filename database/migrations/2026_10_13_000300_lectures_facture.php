<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * « Ma facture » : chaque facture lue par l'IA, pour le quota mensuel du
 * commerçant (selon son offre) ; et les intitulés des deux blocs de la
 * facture « distributeur », que chacun nomme à sa façon (« Liquide nu »,
 * « Frais de livraison »…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lectures_facture', function (Blueprint $t): void {
            $t->id();
            // Le commerçant : le titulaire de l'abonnement, toutes boutiques confondues.
            $t->uuid('proprietaire_id')->index();
            $t->uuid('boutique_id');
            $t->uuid('user_id');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->string('libelle_marchandises', 40)->nullable();
            $t->string('libelle_frais', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['libelle_marchandises', 'libelle_frais']));
        Schema::dropIfExists('lectures_facture');
    }
};
