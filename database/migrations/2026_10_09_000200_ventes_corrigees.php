<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Corriger une facture : la vente fautive est annulée et remplacée par une
 * nouvelle, dans la même opération. Le lien se lit dans les deux sens
 * (« remplace la facture … », « remplacée par … »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t): void {
            $t->foreignUuid('remplace_vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->foreignUuid('remplacee_par_id')->nullable()->constrained('ventes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('remplace_vente_id');
            $t->dropConstrainedForeignId('remplacee_par_id');
        });
    }
};
