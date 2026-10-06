<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Demande d'abonnement payée en ligne via FedaPay : la transaction créée chez FedaPay. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->string('fedapay_transaction_id', 64)->nullable()->unique()->after('jeko_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->dropUnique(['fedapay_transaction_id']);
            $t->dropColumn('fedapay_transaction_id');
        });
    }
};
