<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Demande d'abonnement payée via pawaPay : le dépôt créé chez pawaPay (UUID choisi par nous). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->uuid('pawapay_deposit_id')->nullable()->unique()->after('fedapay_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->dropUnique(['pawapay_deposit_id']);
            $t->dropColumn('pawapay_deposit_id');
        });
    }
};
