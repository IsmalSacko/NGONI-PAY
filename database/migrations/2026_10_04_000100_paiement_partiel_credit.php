<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crédit = le reste non payé d'une vente, et non plus un moyen de paiement
 * tout ou rien : le client paie ce qu'il peut (montant_paye), la différence
 * (reste_du) est sa dette. Les ventes passées sont reprises telles quelles :
 * à crédit, rien de payé et tout en reste dû ; les autres, tout payé.
 *
 * Un remboursement retient la séance de caisse où il est encaissé : en
 * espèces, il entre dans le fond attendu du tiroir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t): void {
            $t->unsignedBigInteger('montant_paye')->default(0)->after('total');
            $t->unsignedBigInteger('reste_du')->default(0)->after('montant_paye');
        });
        DB::table('ventes')->where('moyen_paiement', 'credit_client')->update(['montant_paye' => 0, 'reste_du' => DB::raw('total')]);
        DB::table('ventes')->where('moyen_paiement', '!=', 'credit_client')->update(['montant_paye' => DB::raw('total'), 'reste_du' => 0]);

        Schema::table('reglements_credit', function (Blueprint $t): void {
            $t->uuid('session_caisse_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn(['montant_paye', 'reste_du']));
        Schema::table('reglements_credit', fn (Blueprint $t) => $t->dropColumn('session_caisse_id'));
    }
};
