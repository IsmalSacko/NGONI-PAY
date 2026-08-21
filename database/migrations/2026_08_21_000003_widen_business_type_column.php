<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le secteur d'activité n'est plus une liste figée dans le schéma.
 *
 * La colonne était un `enum('shop','school','pharmacy','garage','service')` :
 * ajouter un secteur demandait une migration, et un menuisier n'avait que
 * « service » pour se décrire. Pire, la requête d'API acceptait n'importe quelle
 * chaîne — la contrainte ne se manifestait qu'au moment de l'insertion, sous
 * forme d'erreur SQL.
 *
 * La validation revient donc à l'application, où elle appartient
 * ({@see \App\Enums\BusinessType}), et la colonne redevient du texte. Les valeurs
 * existantes sont conservées : `enum` est stocké comme une chaîne, la conversion
 * les laisse intactes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('type', 50)->change();
        });
    }

    public function down(): void
    {
        // Le retour en arrière ne peut pas inventer : un business désormais
        // « menuiserie » n'a pas de place dans l'ancienne liste. Les valeurs
        // hors liste sont ramenées à « service », le fourre-tout d'alors.
        $anciens = ['shop', 'school', 'pharmacy', 'garage', 'service'];

        \App\Models\Business::query()
            ->whereNotIn('type', $anciens)
            ->update(['type' => 'service']);

        Schema::table('businesses', function (Blueprint $table) {
            $table->enum('type', $anciens)->change();
        });
    }
};
