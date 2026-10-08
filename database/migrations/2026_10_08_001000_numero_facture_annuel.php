<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro de facture qui repart à 1 chaque 1er janvier (ABC-2027-0001), sans
 * jamais redonner un numéro déjà remis : l'année fait partie du numéro, et le
 * compteur de l'année ne recule pas, même après « Repartir de zéro ».
 *
 * `ventes.numero` reste la suite interne, unique par boutique ; seul le numéro
 * de facture remis au client a désormais son compteur annuel. L'année en cours
 * continue là où elle en est : la remise à 1 se fera au prochain 1er janvier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee_numero_facture')->nullable();
            $table->unsignedInteger('dernier_numero_facture')->default(0);
        });

        DB::table('boutiques')->update([
            'annee_numero_facture' => (int) now()->format('Y'),
            'dernier_numero_facture' => DB::raw('dernier_numero_vente'),
        ]);
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn(['annee_numero_facture', 'dernier_numero_facture']);
        });
    }
};
