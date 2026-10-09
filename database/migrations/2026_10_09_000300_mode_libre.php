<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mode libre : le propriétaire gère lui-même ses factures (suppression,
 * correction sans limite de date, numérotation), sous sa responsabilité.
 *
 * - acceptations_mode_libre : chaque activation et désactivation, avec le
 *   texte exact accepté, la personne, l'appareil et l'adresse IP (preuve).
 * - journal_suppressions : chaque vente supprimée (numéro, montant, lignes,
 *   auteur), gardé même après une remise à zéro de la boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->boolean('mode_libre')->default(false);
            $t->string('mode_libre_version', 20)->nullable();
        });

        Schema::create('acceptations_mode_libre', function (Blueprint $t): void {
            $t->id();
            $t->uuid('boutique_id')->index();
            $t->uuid('user_id')->nullable();
            // Gardés dans la preuve : ils survivent à la suppression du compte.
            $t->string('nom', 255);
            $t->string('telephone', 30)->nullable();
            $t->string('boutique_nom', 255);
            $t->string('action', 15); // activation | desactivation
            $t->string('version', 20);
            $t->text('texte');
            $t->string('ip', 45)->nullable();
            $t->string('appareil', 255)->nullable();
            $t->timestamp('le');
        });

        Schema::create('journal_suppressions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('boutique_id')->index();
            $t->uuid('user_id')->nullable();
            $t->string('par', 255);
            $t->uuid('vente_id');
            $t->string('numero_facture', 60)->nullable();
            $t->bigInteger('total');
            $t->date('jour')->nullable();
            $t->json('lignes');
            $t->boolean('essai')->default(false);
            $t->timestamp('supprimee_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_suppressions');
        Schema::dropIfExists('acceptations_mode_libre');
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['mode_libre', 'mode_libre_version']));
    }
};
