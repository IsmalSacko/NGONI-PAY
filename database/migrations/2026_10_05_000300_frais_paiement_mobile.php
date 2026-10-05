<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Paiement Mobile Money : les frais de Jèko (1,5 %) sont ajoutés au prix et
 * payés par le commerçant. Le montant de la demande reste le prix de l'offre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->unsignedInteger('frais_mobile')->default(0)->after('jeko_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', fn (Blueprint $t) => $t->dropColumn('frais_mobile'));
    }
};
