<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Factures personnalisées (préfixe, suffixe, année ou non) et mode rodage.
 *
 * Un compteur par série de numéros (`compteurs_facture`, série = préfixe +
 * suffixe + année) : un numéro déjà donné ne resert jamais ; changer de
 * préfixe ouvre une nouvelle série qui repart à 1. Le compteur annuel en
 * cours y est repris tel quel.
 *
 * Mode rodage : ventes d'essai (numérotées ESSAI-…), supprimables ; le
 * passage en mode réel efface tout sauf les comptes et remet les compteurs à zéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->string('facture_prefixe', 10)->nullable();
            $t->string('facture_suffixe', 10)->nullable();
            $t->boolean('facture_annee')->default(true);
            $t->json('compteurs_facture')->nullable();
            $t->boolean('mode_rodage')->default(false);
        });
        Schema::table('ventes', function (Blueprint $t): void {
            $t->boolean('essai')->default(false);
        });

        foreach (DB::table('boutiques')->whereNotNull('annee_numero_facture')->get(['id', 'annee_numero_facture', 'dernier_numero_facture']) as $b) {
            DB::table('boutiques')->where('id', $b->id)->update([
                'compteurs_facture' => json_encode(['*||'.$b->annee_numero_facture => (int) $b->dernier_numero_facture]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('essai'));
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->dropColumn(['facture_prefixe', 'facture_suffixe', 'facture_annee', 'compteurs_facture', 'mode_rodage']);
        });
    }
};
