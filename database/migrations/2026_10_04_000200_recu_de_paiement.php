<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reçu de paiement d'une dette : un numéro par boutique, et la dette avant et
 * après, figées au moment du remboursement — un reçu réimprimé des mois plus
 * tard dit toujours ce qu'il disait. Les anciens remboursements reçoivent leur
 * numéro dans l'ordre ; leurs soldes, inconnus, restent vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglements_credit', function (Blueprint $t): void {
            $t->unsignedInteger('numero')->nullable()->after('id');
            $t->bigInteger('solde_avant')->nullable()->after('montant');
            $t->bigInteger('solde_apres')->nullable()->after('solde_avant');
        });

        foreach (DB::table('reglements_credit')->distinct()->pluck('boutique_id') as $boutique) {
            $n = 0;
            foreach (DB::table('reglements_credit')->where('boutique_id', $boutique)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table('reglements_credit')->where('id', $id)->update(['numero' => ++$n]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('reglements_credit', fn (Blueprint $t) => $t->dropColumn(['numero', 'solde_avant', 'solde_apres']));
    }
};
