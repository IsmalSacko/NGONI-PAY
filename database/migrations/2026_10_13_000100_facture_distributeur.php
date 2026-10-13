<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Facture « distributeur » (grossiste en boissons, Cameroun…) :
 * - client : son code, son NIU, son adresse ;
 * - boutique : boîte postale, taux du PSA (précompte sur achats), modèle de facture ;
 * - article : sa référence (EXP50C) et s'il s'agit de frais (enlèvement, livraison) ;
 * - ligne de vente : référence et frais figés au moment de la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t): void {
            $t->string('code_client', 30)->nullable();
            $t->string('niu', 40)->nullable();
            $t->string('adresse', 255)->nullable();
        });
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->string('boite_postale', 60)->nullable();
            $t->decimal('psa_taux', 5, 2)->nullable();
            $t->string('modele_facture', 20)->default('standard');
        });
        Schema::table('produits', function (Blueprint $t): void {
            $t->string('reference', 30)->nullable();
            $t->boolean('est_frais')->default(false);
        });
        Schema::table('lignes_vente', function (Blueprint $t): void {
            $t->string('reference', 30)->nullable();
            $t->boolean('frais')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn(['reference', 'frais']));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['reference', 'est_frais']));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['boite_postale', 'psa_taux', 'modele_facture']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['code_client', 'niu', 'adresse']));
    }
};
